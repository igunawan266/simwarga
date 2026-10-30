<?php

namespace App\Services\AI;

use App\Exceptions\DomainRuleException;
use App\Libraries\Tools\HttpToolExecutor;
use App\Libraries\Tools\UrlGuard;
use App\Models\AgentToolModel;
use App\Models\AiToolModel;
use App\Models\TenantSubscriptionModel;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Tenant HTTP tools that agents may call (TenantContext scoped). Secret header values are
 * encrypted at rest and never returned by the API. Requires a plan with api_integration.
 */
class ToolService
{
    public const METHODS     = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    public const PARAM_TYPES = ['string', 'number', 'integer', 'boolean'];
    public const RESERVED    = ['delegate_tasks'];

    public function list(): array
    {
        $tools = model(AiToolModel::class)->orderBy('id')->findAll();
        $usage = [];
        foreach (Database::connect()->table('ai_agent_tools at')->select('at.tool_id, a.name')
            ->join('ai_agents a', 'a.id = at.agent_id')->where('at.tenant_id', Services::tenantContext()->id())->where('a.deleted_at', null)
            ->get()->getResultArray() as $row) {
            $usage[(int) $row['tool_id']][] = $row['name'];
        }

        return array_map(fn ($t) => $this->present($t) + ['agents' => $usage[(int) $t['id']] ?? []], $tools);
    }

    public function find(int $id): array
    {
        return model(AiToolModel::class)->find($id) ?? throw new DomainRuleException('Tool tidak ditemukan.', 404);
    }

    public function create(array $data): array
    {
        $this->assertPlanAllows();
        $row         = $this->build($data, null);
        $row['slug'] = $this->uniqueSlug($data['name']);
        $id          = model(AiToolModel::class)->insert($row + ['tool_type' => 'http', 'is_active' => 1], true);
        Services::auditLogger()->record('tool.create', ['entity_type' => 'ai_tool', 'entity_id' => $id, 'new' => ['name' => $data['name'], 'url' => $data['url']]]);

        return $this->present($this->find($id));
    }

    public function update(int $id, array $data): array
    {
        $this->assertPlanAllows();
        $before = $this->find($id);
        $row    = $this->build($data, $before);
        if (array_key_exists('is_active', $data)) {
            $row['is_active'] = (int) (bool) $data['is_active'];
        }
        model(AiToolModel::class)->update($id, $row);
        Services::auditLogger()->record('tool.update', ['entity_type' => 'ai_tool', 'entity_id' => $id, 'new' => array_intersect_key($data, array_flip(['name', 'url', 'method', 'approval_mode', 'is_active']))]);

        return $this->present($this->find($id));
    }

    public function delete(int $id): void
    {
        $this->find($id);
        model(AgentToolModel::class)->where('tool_id', $id)->delete();
        model(AiToolModel::class)->delete($id);
        Services::auditLogger()->record('tool.delete', ['entity_type' => 'ai_tool', 'entity_id' => $id]);
    }

    /** Human-initiated test call (no approval needed: the person clicking is the approver). */
    public function test(int $id, array $input): array
    {
        $this->assertPlanAllows();
        $result = $this->execute($this->find($id), $input);
        Services::auditLogger()->record('tool.test', ['entity_type' => 'ai_tool', 'entity_id' => $id, 'new' => ['status' => $result['status']]]);

        return $result;
    }

    /** @param list<int> $toolIds */
    public function setAgentTools(int $agentId, array $toolIds): void
    {
        $toolIds = array_values(array_unique(array_map('intval', $toolIds)));
        if ($toolIds !== []) {
            $this->assertPlanAllows();
        }
        foreach ($toolIds as $id) {
            $this->find($id); // tenant scoped: other tenants' tools are not found
        }
        model(AgentToolModel::class)->where('agent_id', $agentId)->delete();
        foreach ($toolIds as $id) {
            model(AgentToolModel::class)->insert(['agent_id' => $agentId, 'tool_id' => $id]);
        }
    }

    public function toolIdsForAgent(int $agentId): array
    {
        return array_map('intval', array_column(model(AgentToolModel::class)->select('tool_id')->where('agent_id', $agentId)->findAll(), 'tool_id'));
    }

    /** Active tools of an agent, as Claude tool definitions keyed by tool name. */
    public function definitionsForAgent(int $agentId): array
    {
        if (! $this->planAllows()) {
            return [];
        }
        $ids = $this->toolIdsForAgent($agentId);
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (model(AiToolModel::class)->whereIn('id', $ids)->where('is_active', 1)->findAll() as $t) {
            $out[$t['slug']] = [
                'tool' => $t,
                'definition' => [
                    'name' => $t['slug'], 'description' => $t['description'], 'strict' => true,
                    'inputSchema' => json_decode($t['input_schema'], true),
                ],
            ];
        }

        return $out;
    }

    /** @return array{status: int, body: string, is_error: bool, duration_ms: int} */
    public function execute(array $tool, array $input): array
    {
        $config  = json_decode($tool['config'] ?? '{}', true) ?: [];
        $secrets = [];
        if (! empty($config['secret']['name']) && ! empty($config['secret']['value'])) {
            $secrets[$config['secret']['name']] = $this->decrypt($config['secret']['value']);
        }
        $executor = new HttpToolExecutor(new UrlGuard(config('Ai')->allowPrivateToolTargets()));

        return $executor->execute(['method' => $config['method'] ?? 'GET', 'url' => $config['url'] ?? '', 'headers' => $config['headers'] ?? []], $input, $secrets);
    }

    public function planAllows(): bool
    {
        $sub = model(TenantSubscriptionModel::class)->current();
        if ($sub === null) {
            return false;
        }
        $plan = Database::connect()->table('subscription_plans')->where('id', $sub['plan_id'])->get()->getRowArray();

        return ! empty((json_decode($plan['features'] ?? '{}', true) ?: [])['api_integration']);
    }

    private function assertPlanAllows(): void
    {
        if (! $this->planAllows()) {
            throw new DomainRuleException('Integrasi API & tools tersedia pada paket Professional dan Enterprise.', 403);
        }
    }

    private function build(array $data, ?array $before): array
    {
        $config = $before ? (json_decode($before['config'] ?? '{}', true) ?: []) : [];
        $row    = [];
        foreach (['name', 'description', 'approval_mode'] as $k) {
            if (isset($data[$k])) {
                $row[$k] = $data[$k];
            }
        }
        if (isset($data['method'])) {
            $config['method'] = strtoupper($data['method']);
        }
        if (isset($data['url'])) {
            (new UrlGuard(config('Ai')->allowPrivateToolTargets()))->assertValidTemplate($data['url']);
            $config['url'] = $data['url'];
        }
        if (isset($data['headers'])) {
            $headers = is_array($data['headers']) ? $data['headers'] : (json_decode((string) $data['headers'], true) ?? []);
            foreach ($headers as $name => $value) {
                if (! preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $name) || ! is_scalar($value) || preg_match('/[\r\n]/', (string) $value)) {
                    throw new DomainRuleException("Header \"{$name}\" tidak valid.");
                }
            }
            $config['headers'] = $headers;
        }
        if (! empty($data['secret_header_name'])) {
            if (! preg_match('/^[A-Za-z0-9-]{1,64}$/', $data['secret_header_name'])) {
                throw new DomainRuleException('Nama header rahasia tidak valid.');
            }
            $config['secret']['name'] = $data['secret_header_name'];
        }
        if (isset($data['secret_header_value']) && $data['secret_header_value'] !== '') {
            $config['secret']['value'] = $this->encrypt((string) $data['secret_header_value']);
        }
        if (isset($data['params'])) {
            $params = is_array($data['params']) ? $data['params'] : (json_decode((string) $data['params'], true) ?? null);
            if (! is_array($params)) {
                throw new DomainRuleException('Parameter harus berupa daftar JSON.');
            }
            $row['input_schema'] = json_encode($this->schema($params, $config['url'] ?? ''));
        } elseif ($before === null) {
            $row['input_schema'] = json_encode($this->schema([], $config['url'] ?? ''));
        }
        $row['config'] = json_encode($config);

        return $row;
    }

    /** JSON schema for strict tool use; URL {placeholders} must be declared as required params. */
    private function schema(array $params, string $url): array
    {
        $properties = [];
        $required   = [];
        foreach ($params as $p) {
            $name = (string) ($p['name'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw new DomainRuleException("Nama parameter \"{$name}\" tidak valid (huruf kecil, angka, _).");
            }
            $type = in_array($p['type'] ?? 'string', self::PARAM_TYPES, true) ? $p['type'] : 'string';
            $properties[$name] = ['type' => $type, 'description' => mb_substr((string) ($p['description'] ?? ''), 0, 300)];
            if (! empty($p['required'])) {
                $required[] = $name;
            }
        }
        preg_match_all('/\{([a-z0-9_]+)\}/i', $url, $m);
        foreach ($m[1] as $placeholder) {
            if (! isset($properties[$placeholder])) {
                throw new DomainRuleException("Parameter \"{$placeholder}\" dipakai di URL tetapi belum didefinisikan.");
            }
            $required[] = $placeholder;
        }
        // Strict mode: every property listed in required; optional ones become nullable.
        foreach ($properties as $name => &$prop) {
            if (! in_array($name, $required, true)) {
                $prop['type'] = [$prop['type'], 'null'];
            }
        }
        unset($prop);

        return ['type' => 'object', 'properties' => (object) $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    private function uniqueSlug(string $name): string
    {
        $base = substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'tool', 0, 56);
        if (in_array($base, self::RESERVED, true)) {
            $base .= '_tool';
        }
        $slug = $base;
        for ($i = 2; model(AiToolModel::class)->where('slug', $slug)->countAllResults() > 0; $i++) {
            $slug = "{$base}_{$i}";
        }

        return $slug;
    }

    private function encrypt(string $value): string
    {
        try {
            return base64_encode(Services::encrypter(config('Encryption'), false)->encrypt($value));
        } catch (Throwable) {
            throw new DomainRuleException('Kunci enkripsi server belum diatur (encryption.key). Header rahasia tidak dapat disimpan.', 503);
        }
    }

    private function decrypt(string $value): string
    {
        try {
            return Services::encrypter(config('Encryption'), false)->decrypt(base64_decode($value));
        } catch (Throwable) {
            throw new DomainRuleException('Header rahasia tidak dapat didekripsi (kunci enkripsi berubah?). Simpan ulang nilainya.');
        }
    }

    private function present(array $t): array
    {
        $config = json_decode($t['config'] ?? '{}', true) ?: [];
        $schema = json_decode($t['input_schema'] ?? '{}', true) ?: [];

        return [
            'id' => (int) $t['id'], 'name' => $t['name'], 'slug' => $t['slug'], 'description' => $t['description'],
            'method' => $config['method'] ?? 'GET', 'url' => $config['url'] ?? '', 'headers' => $config['headers'] ?? (object) [],
            'secret_header_name' => $config['secret']['name'] ?? null, 'has_secret' => ! empty($config['secret']['value']),
            'params' => array_map(static fn ($name, $p) => [
                'name' => $name, 'type' => is_array($p['type']) ? $p['type'][0] : $p['type'], 'description' => $p['description'] ?? '',
                'required' => ! is_array($p['type']),
            ], array_keys((array) ($schema['properties'] ?? [])), array_values((array) ($schema['properties'] ?? []))),
            'approval_mode' => $t['approval_mode'], 'is_active' => (bool) $t['is_active'],
        ];
    }
}
