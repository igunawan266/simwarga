<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

/**
 * Server-side tenant isolation: a tenant can never read another tenant's office data,
 * whatever ids it puts in the URL or the session.
 */
final class TenantIsolationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';
    protected $seed        = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Services::tenantContext()->clear();
    }

    private function sessionFor(string $email, ?string $tenantSlug = null): array
    {
        $db     = Database::connect();
        $userId = (int) $db->table('users')->where('email', $email)->get()->getRow()->id;
        $tenant = $tenantSlug ? (int) $db->table('tenants')->where('slug', $tenantSlug)->get()->getRow()->id : null;

        return ['user_id' => $userId, 'active_tenant_id' => $tenant];
    }

    private function roomIdOf(string $tenantSlug, string $roomType): int
    {
        return (int) Database::connect()->table('rooms r')
            ->select('r.id')
            ->join('tenants t', 't.id = r.tenant_id')
            ->join('room_types rt', 'rt.id = r.room_type_id')
            ->where('t.slug', $tenantSlug)->where('rt.slug', $roomType)
            ->get()->getRow()->id;
    }

    public function testPublicDirectoryExposesOnlyDirectoryFields(): void
    {
        $result = $this->get('api/v1/building');
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertCount(13, $json['data']['floors']);
        $this->assertStringNotContainsString('system_prompt', $result->getJSON());
        $this->assertStringNotContainsString('contact_email', $result->getJSON());
    }

    public function testOfficeRequiresLogin(): void
    {
        $this->get('api/v1/office')->assertStatus(401);
    }

    public function testTenantSeesOwnOffice(): void
    {
        $result = $this->withSession($this->sessionFor('owner@demo.test', 'nusantara-digital'))->get('api/v1/office');
        $result->assertStatus(200);
        $data = json_decode($result->getJSON(), true)['data'];

        $this->assertSame('Nusantara Digital Group', $data['tenant']['name']);
        $this->assertSame(7, $data['office']['floor_number']);
        $this->assertCount(15, $data['agents']);
        $this->assertArrayNotHasKey('system_prompt', $data['agents'][0]);
    }

    public function testTenantCannotReadAnotherTenantsRoom(): void
    {
        $foreignRoom = $this->roomIdOf('nusantara-digital', 'ceo_office');
        $ownRoom     = $this->roomIdOf('arunika-logistics', 'ceo_office');
        $session     = $this->sessionFor('owner2@demo.test', 'arunika-logistics');

        $this->withSession($session)->get("api/v1/office/rooms/{$foreignRoom}")->assertStatus(404);
        Services::tenantContext()->clear();
        $this->withSession($session)->get("api/v1/office/rooms/{$ownRoom}")->assertStatus(200);
    }

    public function testForgedActiveTenantInSessionIsRejected(): void
    {
        // owner2 is not a member of Nusantara Digital — pointing the session at it must fail.
        $this->withSession($this->sessionFor('owner2@demo.test', 'nusantara-digital'))
            ->get('api/v1/office')
            ->assertStatus(403);
    }

    public function testSuperAdminWithoutMembershipHasNoTenantOffice(): void
    {
        $this->withSession($this->sessionFor('admin@demo.test'))->get('api/v1/office')->assertStatus(403);
    }
}
