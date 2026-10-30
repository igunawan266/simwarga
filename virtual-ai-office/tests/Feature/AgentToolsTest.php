<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use App\Exceptions\DomainRuleException;
use App\Libraries\AI\LlmException;
use App\Libraries\Tools\UrlGuard;
use App\Services\AI\AgentWorker;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\FakeLlm;

/** Tenant HTTP tools: plan gating, secrets, SSRF guard, approvals, tool-use loop, resume. */
final class AgentToolsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const CSRF = 'test-csrf-token-0123456789abcdef';
    private const PORT = 8099;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = DatabaseSeeder::class;

    private static $server;
    private static string $log;
    private FakeLlm $llm;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$log = sys_get_temp_dir() . '/vao-tool-endpoint-' . getmypid() . '.jsonl';
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::PORT, __DIR__ . '/../_support/tool-endpoint.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, array_merge(getenv(), ['TOOL_LOG' => self::$log]));
        usleep(400000);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        @unlink(self::$log);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        @file_put_contents(self::$log, '');
        Services::tenantContext()->clear();
        $this->llm = new FakeLlm();
        Services::injectMock('llm', $this->llm);
        config('Encryption')->key = hex2bin(str_repeat('ab', 32));
        config('Ai')->toolsAllowPrivateNetwork = true; // the fake API runs on localhost
    }

    private function as(string $email, string $slug = 'nusantara-digital'): static
    {
        $db = Database::connect();
        Services::tenantContext()->clear();

        return $this->withSession([
            'user_id'          => (int) $db->table('users')->where('email', $email)->get()->getRow()->id,
            'active_tenant_id' => (int) $db->table('tenants')->where('slug', $slug)->get()->getRow()->id,
            'csrf_token'       => self::CSRF,
        ])->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function json($r): array
    {
        return json_decode($r->getJSON(), true);
    }

    private function hits(): array
    {
        return array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents(self::$log))));
    }

    private function createTool(string $mode = 'approval', string $url = 'http://127.0.0.1:8099/orders/{order_id}'): array
    {
        $res = $this->as('owner@demo.test')->post('api/v1/office/tools', [
            'name' => 'Cek Status Pesanan', 'description' => 'Mengambil status pengiriman pesanan dari CRM berdasarkan nomor pesanan.',
            'method' => 'GET', 'url' => $url, 'approval_mode' => $mode,
            'params' => json_encode([['name' => 'order_id', 'type' => 'string', 'description' => 'Nomor pesanan', 'required' => true],
                ['name' => 'detail', 'type' => 'boolean', 'description' => 'Tampilkan detail', 'required' => false]]),
            'secret_header_name' => 'X-Api-Key', 'secret_header_value' => 'rahasia-crm-123',
        ]);
        $res->assertStatus(201);

        return $this->json($res)['data']['tool'];
    }

    private function agentWithTool(array $tool): int
    {
        $sari = (int) Database::connect()->table('ai_agents')->where('name', 'Sari')->orderBy('id')->get()->getRow()->id;
        $this->as('owner@demo.test')->withBody("tool_ids={$tool['id']}")->patch("api/v1/office/agents/{$sari}")->assertStatus(200);

        return $sari;
    }

    private function assign(int $agentId): int
    {
        return (int) $this->json($this->as('owner@demo.test')->post("api/v1/office/agents/{$agentId}/tasks", [
            'title' => 'Status pesanan', 'instruction' => 'Pelanggan menanyakan status pesanan INV-77. Cek dan buat balasan.',
        ]))['data']['task']['id'];
    }

    private function drain(): void
    {
        Services::tenantContext()->clear();
        $w = new AgentWorker();
        for ($i = 0; $i < 20 && $w->runNext() !== null; $i++) {
        }
    }

    private function task(int $id): array
    {
        return Database::connect()->table('agent_tasks')->where('id', $id)->get()->getRowArray();
    }

    // ------------------------------------------------------------------ management & security

    public function testPlanGatingAndSecretsNeverLeave(): void
    {
        $this->as('owner2@demo.test', 'arunika-logistics')->post('api/v1/office/tools', [
            'name' => 'Tool X', 'description' => 'Deskripsi tool yang cukup panjang.', 'method' => 'GET', 'url' => 'https://example.com/x',
        ])->assertStatus(403);

        $tool = $this->createTool();
        $this->assertSame('cek_status_pesanan', $tool['slug']);
        $this->assertTrue($tool['has_secret']);
        $this->assertStringNotContainsString('rahasia-crm-123', json_encode($tool));
        $stored = Database::connect()->table('ai_tools')->where('id', $tool['id'])->get()->getRow()->config;
        $this->assertStringNotContainsString('rahasia-crm-123', $stored, 'secret is encrypted at rest');
        $this->assertStringNotContainsString('rahasia-crm-123', $this->as('owner@demo.test')->get('api/v1/office/tools')->getJSON());
        $this->as('staff@demo.test')->get('api/v1/office/tools')->assertStatus(403);
        $this->as('owner2@demo.test', 'arunika-logistics')->delete("api/v1/office/tools/{$tool['id']}")->assertStatus(404);
    }

    public function testUrlGuardBlocksInternalTargets(): void
    {
        $guard = new UrlGuard(false);
        foreach (['https://127.0.0.1/x', 'https://169.254.169.254/latest/meta-data', 'https://10.0.0.5/', 'https://[::1]/', 'https://100.64.1.1/'] as $url) {
            try {
                $guard->resolve($url);
                $this->fail("{$url} should be blocked");
            } catch (DomainRuleException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['http://example.com/x', 'https://user:pw@example.com/', 'https://{host}.example.com/', 'ftp://example.com/'] as $url) {
            try {
                $guard->assertValidTemplate($url);
                $this->fail("{$url} should be rejected");
            } catch (DomainRuleException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(DomainRuleException::class);
        $guard->resolve('https://example.com:22/');
    }

    public function testUndeclaredUrlPlaceholderIsRejectedAndTestCallSendsSecret(): void
    {
        $this->as('owner@demo.test')->post('api/v1/office/tools', [
            'name' => 'Salah', 'description' => 'URL memakai parameter yang tidak ada.', 'method' => 'GET', 'url' => 'http://127.0.0.1:8099/orders/{nomor}',
        ])->assertStatus(422);

        $tool   = $this->createTool();
        $result = $this->json($this->as('owner@demo.test')->post("api/v1/office/tools/{$tool['id']}/test", ['input' => '{"order_id":"INV-77","detail":null}']))['data']['result'];
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('dikirim', $result['body']);
        $hit = $this->hits()[0];
        $this->assertSame('/orders/INV-77', $hit['path']);
        $this->assertSame('rahasia-crm-123', $hit['api_key']);
        $this->assertSame([], $hit['query'], 'placeholder consumed, null optional param dropped');
    }

    // ------------------------------------------------------------------ tool-use loop

    public function testApprovalPausesTaskThenResumesWithToolResult(): void
    {
        $tool  = $this->createTool('approval');
        $agent = $this->agentWithTool($tool);
        $this->llm->respond = static fn (array $r, int $i) => $i === 0
            ? FakeLlm::tool('cek_status_pesanan', ['order_id' => 'INV-77', 'detail' => null], 'toolu_A', 'Saya cek dulu.')
            : FakeLlm::text('Pesanan INV-77 sedang dikirim, estimasi 2 hari.');
        $task = $this->assign($agent);
        $this->drain();

        $this->assertSame('waiting_approval', $this->task($task)['status']);
        $this->assertSame([], $this->hits(), 'nothing executed before approval');
        $this->assertSame('cek_status_pesanan', $this->llm->requests[0]['tools'][0]['name']);
        $this->assertTrue($this->llm->requests[0]['tools'][0]['strict']);

        $pending = $this->json($this->as('owner@demo.test')->get('api/v1/office/approvals'))['data']['approvals'];
        $this->assertCount(1, $pending);
        $this->assertSame(['order_id' => 'INV-77', 'detail' => null], $pending[0]['input']);
        $this->as('staff@demo.test')->post("api/v1/office/tool-calls/{$pending[0]['id']}/approve")->assertStatus(403);
        $this->as('owner2@demo.test', 'arunika-logistics')->post("api/v1/office/tool-calls/{$pending[0]['id']}/approve")->assertStatus(404);

        $this->as('owner@demo.test')->post("api/v1/office/tool-calls/{$pending[0]['id']}/approve")->assertStatus(200);
        $this->as('owner@demo.test')->post("api/v1/office/tool-calls/{$pending[0]['id']}/approve")->assertStatus(409);
        $this->assertSame('queued', $this->task($task)['status']);
        $this->drain();

        $row = $this->task($task);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('Pesanan INV-77 sedang dikirim, estimasi 2 hari.', $row['result']);
        $this->assertCount(1, $this->hits());

        // Second call continues the same conversation: assistant turn verbatim + tool_result.
        $messages = $this->llm->requests[1]['messages'];
        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('toolu_A', $messages[1]['content'][1]['id']);
        $this->assertSame('tool_result', $messages[2]['content'][0]['type']);
        $this->assertSame('toolu_A', $messages[2]['content'][0]['tool_use_id']);
        $this->assertStringContainsString('dikirim', $messages[2]['content'][0]['content']);
        $this->assertFalse($messages[2]['content'][0]['is_error']);

        $detail = $this->json($this->as('owner@demo.test')->get("api/v1/office/tasks/{$task}"))['data']['task'];
        $this->assertArrayNotHasKey('state', $detail);
        $this->assertSame('executed', $detail['tool_calls'][0]['status']);
    }

    public function testRejectedCallIsReportedToTheModel(): void
    {
        $agent = $this->agentWithTool($this->createTool('approval'));
        $this->llm->respond = static fn (array $r, int $i) => $i === 0
            ? FakeLlm::tool('cek_status_pesanan', ['order_id' => 'INV-77', 'detail' => null], 'toolu_R')
            : FakeLlm::text('Maaf, data pesanan belum dapat dicek.');
        $task = $this->assign($agent);
        $this->drain();
        $id = $this->json($this->as('owner@demo.test')->get('api/v1/office/approvals'))['data']['approvals'][0]['id'];
        $this->as('owner@demo.test')->post("api/v1/office/tool-calls/{$id}/reject", ['note' => 'Data sensitif'])->assertStatus(200);
        $this->drain();

        $result = $this->llm->requests[1]['messages'][2]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Data sensitif', $result['content']);
        $this->assertSame('completed', $this->task($task)['status']);
        $this->assertSame([], $this->hits());
    }

    public function testAutoToolsRunInlineUnknownToolsFailSoftAndRoundsAreCapped(): void
    {
        $agent = $this->agentWithTool($this->createTool('auto'));
        $this->llm->respond = static fn (array $r, int $i) => match ($i) {
            0 => FakeLlm::tool('hapus_semua_data', [], 'toolu_U'),
            1 => FakeLlm::tool('cek_status_pesanan', ['order_id' => 'INV-77', 'detail' => true], 'toolu_B'),
            default => FakeLlm::text('Selesai.'),
        };
        $task = $this->assign($agent);
        $this->drain();
        $this->assertSame('completed', $this->task($task)['status']);
        $this->assertStringContainsString('tidak tersedia', $this->llm->requests[1]['messages'][2]['content'][0]['content']);
        $this->assertSame(['detail' => '1'], $this->hits()[0]['query']);

        config('Ai')->maxToolRounds = 3;
        $this->llm->respond = static fn (array $r, int $i) => FakeLlm::tool('cek_status_pesanan', ['order_id' => "L{$i}", 'detail' => null], "toolu_L{$i}");
        $loop = $this->assign($agent);
        $this->drain();
        $this->assertSame('failed', $this->task($loop)['status']);
        $this->assertStringContainsString('terlalu banyak langkah', $this->task($loop)['error_message']);
    }

    public function testRetryAfterToolRunDoesNotExecuteItAgain(): void
    {
        $agent = $this->agentWithTool($this->createTool('auto'));
        $fail  = true;
        $this->llm->respond = static function (array $r, int $i) use (&$fail) {
            if ($i === 0) {
                return FakeLlm::tool('cek_status_pesanan', ['order_id' => 'INV-77', 'detail' => null], 'toolu_C');
            }
            if ($fail) {
                $fail = false;
                throw new LlmException('Layanan AI sedang sibuk', true);
            }

            return FakeLlm::text('Pesanan dalam pengiriman.');
        };
        $task = $this->assign($agent);
        $this->drain();
        $this->assertSame('queued', $this->task($task)['status']);
        Database::connect()->table('agent_tasks')->where('id', $task)->update(['queued_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->drain();

        $this->assertSame('completed', $this->task($task)['status']);
        $this->assertCount(1, $this->hits(), 'the HTTP tool ran exactly once across the retry');
        $this->assertSame(['user', 'assistant', 'user'], array_column(end($this->llm->requests)['messages'], 'role'));
    }
}
