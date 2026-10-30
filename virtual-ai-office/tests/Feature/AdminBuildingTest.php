<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

/** Super Admin management of offices, tenants and plans. */
final class AdminBuildingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const CSRF = 'test-csrf-token-0123456789abcdef';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Services::tenantContext()->clear();
    }

    private function as(string $email, ?string $tenantSlug = null): static
    {
        $db = Database::connect();

        return $this->withSession([
            'user_id'          => (int) $db->table('users')->where('email', $email)->get()->getRow()->id,
            'active_tenant_id' => $tenantSlug ? (int) $db->table('tenants')->where('slug', $tenantSlug)->get()->getRow()->id : null,
            'csrf_token'       => self::CSRF,
        ])->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function officeOnFloor(int $n): array
    {
        return Database::connect()->table('offices o')->select('o.*')->join('floors f', 'f.id = o.floor_id')
            ->where('f.floor_number', $n)->get()->getRowArray();
    }

    private function json($result): array
    {
        return json_decode($result->getJSON(), true);
    }

    public function testTenantOwnerCannotUseAdminApi(): void
    {
        $this->as('owner@demo.test', 'nusantara-digital')->get('api/v1/admin/floors')->assertStatus(403);
        $this->withSession([])->get('api/v1/admin/floors')->assertStatus(401);
    }

    public function testStateChangeWithoutCsrfTokenIsRejected(): void
    {
        $office = $this->officeOnFloor(7);
        $this->expectException(SecurityException::class);
        $this->withSession(['user_id' => (int) Database::connect()->table('users')->where('email', 'admin@demo.test')->get()->getRow()->id])
            ->post("api/v1/admin/offices/{$office['id']}/release");
    }

    public function testCreateTenantThenAssignVacantOfficeProvisionsRooms(): void
    {
        $created = $this->as('admin@demo.test')->post('api/v1/admin/tenants', [
            'company_name' => 'Garuda Analytics', 'industry' => 'Data', 'brand_color' => '#f59e0b', 'status' => 'active',
        ]);
        $created->assertStatus(201);
        $tenantId = (int) $this->json($created)['data']['id'];
        $this->assertSame('garuda-analytics', $this->json($created)['data']['slug']);

        $office = $this->officeOnFloor(4);
        $this->as('admin@demo.test')->post("api/v1/admin/offices/{$office['id']}/assign", ['tenant_id' => $tenantId])->assertStatus(200);

        $db = Database::connect();
        $this->assertSame($tenantId, (int) $db->table('offices')->where('id', $office['id'])->get()->getRow()->tenant_id);
        $this->assertSame(10, $db->table('rooms')->where('tenant_id', $tenantId)->countAllResults());
        // No plan yet: only the 4 common facilities are usable.
        $this->assertSame(4, $db->table('rooms')->where('tenant_id', $tenantId)->where('is_enabled', 1)->countAllResults());
        $this->assertSame('occupied', $db->table('floors')->where('floor_number', 4)->get()->getRow()->status);
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'admin.office.assign')->countAllResults());

        // The new tenant now appears in the public building directory.
        $floors = $this->json($this->get('api/v1/building'))['data']['floors'];
        $this->assertSame('Garuda Analytics', $floors[4]['tenant']['name']);
    }

    public function testCannotAssignOccupiedOfficeOrSecondOffice(): void
    {
        $sagara = (int) Database::connect()->table('tenants')->where('slug', 'sagara-retail')->get()->getRow()->id;

        $occupied = $this->officeOnFloor(7);
        $this->as('admin@demo.test')->post("api/v1/admin/offices/{$occupied['id']}/assign", ['tenant_id' => $sagara])->assertStatus(422);

        // Sagara (Starter, max 1 office) already leases floor 5.
        $vacant = $this->officeOnFloor(4);
        $result = $this->as('admin@demo.test')->post("api/v1/admin/offices/{$vacant['id']}/assign", ['tenant_id' => $sagara]);
        $result->assertStatus(422);
        $this->assertStringContainsString('batas paket', $this->json($result)['messages']['error']);
    }

    public function testReleaseOfficeRemovesRoomsAndKeepsAgents(): void
    {
        $db       = Database::connect();
        $office   = $this->officeOnFloor(3);
        $tenantId = (int) $office['tenant_id'];
        $agents   = $db->table('ai_agents')->where('tenant_id', $tenantId)->countAllResults();

        $this->as('admin@demo.test')->post("api/v1/admin/offices/{$office['id']}/release")->assertStatus(200);

        $this->assertNull($db->table('offices')->where('id', $office['id'])->get()->getRow()->tenant_id);
        $this->assertSame(0, $db->table('rooms')->where('office_id', $office['id'])->countAllResults());
        $this->assertSame($agents, $db->table('ai_agents')->where('tenant_id', $tenantId)->where('room_id', null)->countAllResults());
        $this->assertSame('available', $db->table('floors')->where('floor_number', 3)->get()->getRow()->status);
    }

    public function testUpdatePlanPriceAndLimitsIsAudited(): void
    {
        $plan = Database::connect()->table('subscription_plans')->where('code', 'STARTER')->get()->getRow();
        $result = $this->as('admin@demo.test')->withBody(http_build_query(['price' => '349000', 'max_agents' => '3']))->patch("api/v1/admin/plans/{$plan->id}");
        $result->assertStatus(200);

        $data = $this->json($result)['data'];
        $this->assertSame(349000, $data['price']);
        $this->assertSame(3, $data['max_agents']);
        $this->assertSame(1, Database::connect()->table('audit_logs')->where('action', 'admin.plan.update')->countAllResults());
    }

    public function testInvalidInputIsRejected(): void
    {
        $plan = Database::connect()->table('subscription_plans')->where('code', 'STARTER')->get()->getRow();
        $this->as('admin@demo.test')->withBody('price=-5')->patch("api/v1/admin/plans/{$plan->id}")->assertStatus(400);
        $this->as('admin@demo.test')->post('api/v1/admin/tenants', ['company_name' => '', 'brand_color' => 'red'])->assertStatus(400);
    }
}
