<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\AI\LlmException;
use App\Libraries\AI\LlmResponse;
use App\Services\AI\AgentWorker;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\FakeLlm;

/** Agent management, plan limits, task queue, multi-agent delegation, usage and isolation. */
final class AgentEngineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const CSRF = 'test-csrf-token-0123456789abcdef';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = DatabaseSeeder::class;

    private FakeLlm $llm;

    protected function setUp(): void
    {
        parent::setUp();
        Services::tenantContext()->clear();
        $this->llm = new FakeLlm();
        Services::injectMock('llm', $this->llm);
    }

    private function as(string $email, string $slug): static
    {
        $db = Database::connect();
        Services::tenantContext()->clear();

        return $this->withSession([
            'user_id'          => (int) $db->table('users')->where('email', $email)->get()->getRow()->id,
            'active_tenant_id' => (int) $db->table('tenants')->where('slug', $slug)->get()->getRow()->id,
            'csrf_token'       => self::CSRF,
        ])->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function owner(): static
    {
        return $this->as('owner@demo.test', 'nusantara-digital');
    }

    private function agentId(string $name, string $slug = 'nusantara-digital'): int
    {
        $tenant = (int) Database::connect()->table('tenants')->where('slug', $slug)->get()->getRow()->id;

        return (int) Database::connect()->table('ai_agents')->where('tenant_id', $tenant)->where('name', $name)->get()->getRow()->id;
    }

    private function roomId(string $type, string $slug = 'nusantara-digital'): int
    {
        $tenant = (int) Database::connect()->table('tenants')->where('slug', $slug)->get()->getRow()->id;
        $typeId = (int) Database::connect()->table('room_types')->where('slug', $type)->get()->getRow()->id;

        return (int) Database::connect()->table('rooms')->where('tenant_id', $tenant)->where('room_type_id', $typeId)->get()->getRow()->id;
    }

    private function json($result): array
    {
        return json_decode($result->getJSON(), true);
    }

    private function assignTask(int $agentId, string $title = 'Rencana konten Q4', string $instruction = 'Buat rencana konten media sosial untuk kuartal depan.'): int
    {
        $res = $this->owner()->post("api/v1/office/agents/{$agentId}/tasks", ['title' => $title, 'instruction' => $instruction, 'priority' => 'normal']);
        $res->assertStatus(201);

        return (int) $this->json($res)['data']['task']['id'];
    }

    private function drain(): int
    {
        Services::tenantContext()->clear();
        $worker = new AgentWorker();
        $n      = 0;
        while ($worker->runNext() !== null && $n < 50) {
            $n++;
        }

        return $n;
    }

    private function task(int $id): array
    {
        return Database::connect()->table('agent_tasks')->where('id', $id)->get()->getRowArray();
    }

    // ------------------------------------------------------------------ management

    public function testPlanAgentLimitIsEnforcedOnTheServer(): void
    {
        // Nusantara: Professional (15 agents) and already has 15.
        $res = $this->owner()->post('api/v1/office/agents', ['name' => 'Nova', 'job_title' => 'AI Analyst', 'department' => 'Data']);
        $res->assertStatus(403);
        $this->assertStringContainsString('maksimal 15', $res->getJSON());

        // Arunika: Business (5) with 5 → also full; Bumi Fintech (Enterprise, 100) can add.
        Database::connect()->table('ai_agents')->where('id', $this->agentId('Kenzo'))->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $created = $this->owner()->post('api/v1/office/agents', [
            'name' => 'Nova', 'job_title' => 'AI Analyst', 'department' => 'Data', 'room_id' => $this->roomId('marketing'),
            'system_prompt' => 'Kamu analis data.', 'effort' => 'high',
        ]);
        $created->assertStatus(201);
        $agent = $this->json($created)['data']['agent'];
        $this->assertFalse($agent['is_active']);
        $this->assertSame('high', $agent['effort']);
        $this->assertSame('claude-opus-5-5', $agent['model']['key']);
    }

    public function testRoomAndManagerValidation(): void
    {
        $maya = $this->agentId('Maya');
        // Another tenant's room is invisible (404); a room locked by the plan is refused.
        $this->owner()->withBody('room_id=' . $this->roomId('marketing', 'arunika-logistics'))->patch("api/v1/office/agents/{$maya}")->assertStatus(404);
        Database::connect()->table('rooms')->where('id', $this->roomId('finance'))->update(['is_enabled' => 0]);
        $this->owner()->withBody('room_id=' . $this->roomId('finance'))->patch("api/v1/office/agents/{$maya}")->assertStatus(422);

        // Atlas manages Maya; making Maya Atlas's manager would be a cycle.
        $atlas = $this->agentId('Atlas');
        $this->owner()->withBody("manager_agent_id={$maya}")->patch("api/v1/office/agents/{$atlas}")->assertStatus(422);
        $this->owner()->withBody("manager_agent_id={$atlas}")->patch("api/v1/office/agents/{$atlas}")->assertStatus(422);
    }

    public function testStaffCanAssignTasksButNotManageAgents(): void
    {
        $staff = $this->as('staff@demo.test', 'nusantara-digital');
        $staff->post('api/v1/office/agents', ['name' => 'X', 'job_title' => 'AI X', 'department' => 'X'])->assertStatus(403);
        $this->as('staff@demo.test', 'nusantara-digital')->delete('api/v1/office/agents/' . $this->agentId('Maya'))->assertStatus(403);
        $seen = $this->json($this->as('staff@demo.test', 'nusantara-digital')->get('api/v1/office/agents/' . $this->agentId('Maya')))['data'];
        $this->assertArrayNotHasKey('system_prompt', $seen['agent']);
        $this->assertFalse($seen['can_manage']);
        $this->assertArrayHasKey('system_prompt', $this->json($this->owner()->get('api/v1/office/agents/' . $this->agentId('Maya')))['data']['agent']);
        $this->as('staff@demo.test', 'nusantara-digital')->post('api/v1/office/agents/' . $this->agentId('Maya') . '/tasks', [
            'title' => 'Ide caption', 'instruction' => 'Tulis tiga ide caption Instagram untuk produk baru.',
        ])->assertStatus(201);
    }

    public function testInactiveAgentCannotTakeTasksAndDeleteCancelsOpenWork(): void
    {
        $maya = $this->agentId('Maya');
        $this->owner()->post("api/v1/office/agents/{$maya}/deactivate")->assertStatus(200);
        $this->owner()->post("api/v1/office/agents/{$maya}/tasks", ['title' => 'Tugas', 'instruction' => 'Instruksi yang cukup panjang.'])->assertStatus(422);

        $this->owner()->post("api/v1/office/agents/{$maya}/activate")->assertStatus(200);
        $task = $this->assignTask($maya);
        $this->owner()->delete("api/v1/office/agents/{$maya}")->assertStatus(200);
        $this->assertSame('cancelled', $this->task($task)['status']);
        $this->assertSame(0, $this->drain());
    }

    // ------------------------------------------------------------------ execution

    public function testWorkerCompletesTaskAndRecordsUsage(): void
    {
        $maya = $this->agentId('Maya');
        $task = $this->assignTask($maya);
        $this->assertSame(1, $this->drain());

        $row = $this->task($task);
        $this->assertSame('completed', $row['status']);
        $this->assertStringContainsString('Rencana konten Q4', $row['result']);
        $this->assertSame(120, (int) $row['input_tokens']);
        $this->assertSame(80, (int) $row['output_tokens']);

        $agent = Database::connect()->table('ai_agents')->where('id', $maya)->get()->getRowArray();
        $this->assertSame('online', $agent['status']);
        $this->assertSame(200, (int) $agent['tokens_used_month']);

        $req = $this->llm->requests[0];
        $this->assertSame('claude-opus-5-5', $req['model']);
        $this->assertSame('medium', $req['effort']);
        $this->assertSame([], $req['tools'], 'a non-manager gets no delegation tool');
        $this->assertStringContainsString('Nusantara Digital Group', $req['system']);

        $detail = $this->json($this->owner()->get("api/v1/office/tasks/{$task}"))['data'];
        $this->assertSame(['task_queued', 'task_started', 'llm_call', 'task_completed'], array_column($detail['logs'], 'event'));
        $this->assertCount(2, $detail['conversation']);
    }

    public function testManagerDelegatesToTeamAndSynthesisesResults(): void
    {
        $atlas = $this->agentId('Atlas');
        $maya  = $this->agentId('Maya');
        $raka  = $this->agentId('Raka');
        $arunikaAgent = $this->agentId('Aira', 'arunika-logistics'); // not in Atlas's team

        $this->llm->respond = static function (array $r, int $i) use ($maya, $raka, $arunikaAgent) {
            if ($i === 0) {
                return FakeLlm::delegate([
                    ['agent_id' => $maya, 'title' => 'Strategi kampanye', 'instruction' => 'Susun strategi kampanye peluncuran.'],
                    ['agent_id' => $raka, 'title' => 'Target penjualan', 'instruction' => 'Tentukan target dan skrip penjualan.'],
                    ['agent_id' => $arunikaAgent, 'title' => 'Bocor', 'instruction' => 'Agent tenant lain tidak boleh menerima tugas.'],
                ]);
            }
            if (str_contains($r['messages'][0]['content'], 'Anggota tim telah menyelesaikan')) {
                return FakeLlm::text('LAPORAN AKHIR TERPADU');
            }

            return FakeLlm::text('Bagian dari ' . (str_contains($r['system'], 'Maya') ? 'Maya' : 'Raka'));
        };

        $parent = $this->assignTask($atlas, 'Peluncuran produk', 'Siapkan rencana peluncuran produk baru lintas tim.');
        $this->assertSame(4, $this->drain()); // manager -> 2 children -> manager synthesis

        $children = Database::connect()->table('agent_tasks')->where('parent_task_id', $parent)->orderBy('id')->get()->getResultArray();
        $this->assertSame([$maya, $raka], array_map('intval', array_column($children, 'agent_id')), 'only real team members receive work');
        $this->assertSame(['completed', 'completed'], array_column($children, 'status'));
        $this->assertSame($atlas, (int) $children[0]['assigned_by_agent_id']);

        $this->assertSame('completed', $this->task($parent)['status']);
        $this->assertSame('LAPORAN AKHIR TERPADU', $this->task($parent)['result']);

        $first = $this->llm->requests[0];
        $this->assertSame('delegate_tasks', $first['tools'][0]['name']);
        $this->assertTrue($first['tools'][0]['strict']);
        $synthesis = end($this->llm->requests);
        $this->assertStringContainsString('Bagian dari Maya', $synthesis['messages'][0]['content']);
        $this->assertStringContainsString('Bagian dari Raka', $synthesis['messages'][0]['content']);
        $this->assertSame([], $synthesis['tools']);

        $detail = $this->json($this->owner()->get("api/v1/office/tasks/{$parent}"))['data'];
        $this->assertCount(2, $detail['children']);
        $this->assertContains('delegation', array_column($detail['logs'], 'event'));
    }

    public function testTransientErrorsRetryWithBackoffAndPermanentErrorsFail(): void
    {
        $maya = $this->agentId('Maya');
        $this->llm->respond = static fn () => throw new LlmException('Layanan AI sedang sibuk', true);
        $task = $this->assignTask($maya);
        $this->drain();

        $row = $this->task($task);
        $this->assertSame('queued', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertGreaterThan(time() + 20, strtotime($row['queued_at']));
        $this->assertSame(0, $this->drain(), 'not picked up again before the backoff');

        $this->llm->respond = static fn () => throw new LlmException('Kredensial AI ditolak', false);
        Database::connect()->table('agent_tasks')->where('id', $task)->update(['queued_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->drain();
        $this->assertSame('failed', $this->task($task)['status']);
        $this->assertSame('error', Database::connect()->table('ai_agents')->where('id', $maya)->get()->getRow()->status);
    }

    public function testRefusalAndTokenLimitsFailTheTask(): void
    {
        $maya = $this->agentId('Maya');
        $this->llm->respond = static fn () => new LlmResponse('', [], 'refusal', 50, 0, 'claude-opus-5-5', 'cyber');
        $task = $this->assignTask($maya);
        $this->drain();
        $this->assertSame('failed', $this->task($task)['status']);
        $this->assertStringContainsString('cyber', $this->task($task)['error_message']);

        // Agent monthly limit reached: the model is not called at all.
        Database::connect()->table('ai_agents')->where('id', $maya)->update(['token_limit_monthly' => 10, 'status' => 'online']);
        $calls = count($this->llm->requests);
        $task2 = $this->assignTask($maya);
        $this->drain();
        $this->assertSame('failed', $this->task($task2)['status']);
        $this->assertStringContainsString('Batas token', $this->task($task2)['error_message']);
        $this->assertCount($calls, $this->llm->requests);
    }

    public function testTasksAndAgentsAreTenantIsolated(): void
    {
        $task = $this->assignTask($this->agentId('Maya'));
        $other = $this->as('owner2@demo.test', 'arunika-logistics');
        $other->get("api/v1/office/tasks/{$task}")->assertStatus(404);
        $this->as('owner2@demo.test', 'arunika-logistics')->post('api/v1/office/agents/' . $this->agentId('Maya') . '/tasks', [
            'title' => 'Ambil alih', 'instruction' => 'Mencoba memberi tugas ke agent tenant lain.',
        ])->assertStatus(404);
        $this->assertSame([], $this->json($this->as('owner2@demo.test', 'arunika-logistics')->get('api/v1/office/tasks'))['data']['tasks']);

        // The worker runs each task in its own tenant's context.
        $this->as('owner2@demo.test', 'arunika-logistics')->post('api/v1/office/agents/' . $this->agentId('Aira', 'arunika-logistics') . '/tasks', [
            'title' => 'Sambutan', 'instruction' => 'Tulis sambutan untuk tamu kantor.',
        ])->assertStatus(201);
        $this->drain();
        $systems = array_column($this->llm->requests, 'system');
        $this->assertCount(1, array_filter($systems, static fn ($s) => str_contains($s, 'Arunika Logistics')));
        $this->assertCount(1, array_filter($systems, static fn ($s) => str_contains($s, 'Nusantara Digital Group')));
    }
}
