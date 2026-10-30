<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

/** Tenant team management: invitations, roles, removal and acceptance. */
final class MembersTest extends CIUnitTestCase
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

    private function as(?string $email, ?string $tenantSlug = null): static
    {
        $db      = Database::connect();
        $session = ['csrf_token' => self::CSRF];
        if ($email !== null) {
            $session['user_id']          = (int) $db->table('users')->where('email', $email)->get()->getRow()->id;
            $session['active_tenant_id'] = $tenantSlug ? $this->tenantId($tenantSlug) : null;
        }
        Services::tenantContext()->clear();

        return $this->withSession($session)->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function tenantId(string $slug): int
    {
        return (int) Database::connect()->table('tenants')->where('slug', $slug)->get()->getRow()->id;
    }

    private function memberId(string $email, string $slug): int
    {
        $tenantId = $this->tenantId($slug); // resolve first: a nested query would reset the builder's table aliases

        return (int) Database::connect()->table('tenant_members m')->select('m.id')->join('users u', 'u.id = m.user_id')
            ->where('u.email', $email)->where('m.tenant_id', $tenantId)->get()->getRow()->id;
    }

    private function data($result): array
    {
        return json_decode($result->getJSON(), true)['data'] ?? json_decode($result->getJSON(), true);
    }

    private function tokenFrom(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function testOwnerInvitesNewUserWhoAcceptsAndCanEnterOffice(): void
    {
        $result = $this->as('owner@demo.test', 'nusantara-digital')->post('api/v1/office/members', ['email' => 'Sinta@Example.com', 'role' => 'tenant_staff']);
        $result->assertStatus(201);
        $data = $this->data($result);
        $this->assertSame('invited', $data['member']['status']);
        $this->assertSame('sinta@example.com', $data['member']['email']);

        $db = Database::connect();
        $this->assertSame('invited', $db->table('users')->where('email', 'sinta@example.com')->get()->getRow()->status);
        // Only the hash is stored.
        $token = $this->tokenFrom($data['invite_url']);
        $this->assertSame(0, $db->table('tenant_members')->where('invite_token_hash', $token)->countAllResults());

        // Invited placeholder account cannot log in yet.
        $this->as(null)->post('api/v1/session/login', ['email' => 'sinta@example.com', 'password' => 'whatever-123'])->assertStatus(401);

        $this->as(null)->get("invite/{$token}")->assertSee('Bergabung dengan Nusantara Digital Group');
        $accept = $this->as(null)->post("invite/{$token}", ['name' => 'Sinta Dewi', 'password' => 'RahasiaKuat#1', 'password_confirm' => 'RahasiaKuat#1']);
        $accept->assertRedirect();

        $this->assertSame('active', $db->table('users')->where('email', 'sinta@example.com')->get()->getRow()->status);
        $this->as('sinta@example.com', 'nusantara-digital')->get('api/v1/office')->assertStatus(200);

        // The link works only once.
        $this->as(null)->get("invite/{$token}")->assertSee('Tautan tidak berlaku');
    }

    public function testExistingUserMustConfirmWithOwnPassword(): void
    {
        // Super Admin invites Arunika's owner into Nusantara as admin.
        $nusantara = $this->tenantId('nusantara-digital');
        $result    = $this->as('admin@demo.test')->post("api/v1/admin/tenants/{$nusantara}/members", ['email' => 'owner2@demo.test', 'role' => 'tenant_admin']);
        $result->assertStatus(201);
        $token = $this->tokenFrom($this->data($result)['invite_url']);

        $this->as(null)->post("invite/{$token}", ['password' => 'wrong-password'])->assertSee('Password akun Anda salah');
        $this->as(null)->post("invite/{$token}", ['password' => 'DemoOffice#2026'])->assertRedirect();
        $this->assertSame('active', Database::connect()->table('tenant_members')->where('id', $this->memberId('owner2@demo.test', 'nusantara-digital'))->get()->getRow()->status);
    }

    public function testStaffCannotManageMembers(): void
    {
        $this->as('staff@demo.test', 'nusantara-digital')->post('api/v1/office/members', ['email' => 'x@example.com', 'role' => 'tenant_staff'])->assertStatus(403);
        $this->as('staff@demo.test', 'nusantara-digital')->get('api/v1/office/members')->assertStatus(200);
    }

    public function testAdminCanOnlyManageStaff(): void
    {
        // Promote staff to admin first (as owner).
        $staff = $this->memberId('staff@demo.test', 'nusantara-digital');
        $this->as('owner@demo.test', 'nusantara-digital')->withBody('role=tenant_admin')->patch("api/v1/office/members/{$staff}")->assertStatus(200);

        $this->as('staff@demo.test', 'nusantara-digital')->post('api/v1/office/members', ['email' => 'new-staff@example.com', 'role' => 'tenant_staff'])->assertStatus(201);
        $this->as('staff@demo.test', 'nusantara-digital')->post('api/v1/office/members', ['email' => 'new-admin@example.com', 'role' => 'tenant_admin'])->assertStatus(403);
        $owner = $this->memberId('owner@demo.test', 'nusantara-digital');
        $this->as('staff@demo.test', 'nusantara-digital')->delete("api/v1/office/members/{$owner}")->assertStatus(403);
    }

    public function testTenantAlwaysKeepsAnActiveOwnerAndNobodyEditsThemselves(): void
    {
        $owner = $this->memberId('owner@demo.test', 'nusantara-digital');
        $this->as('owner@demo.test', 'nusantara-digital')->delete("api/v1/office/members/{$owner}")->assertStatus(422);

        $nusantara = $this->tenantId('nusantara-digital');
        $result    = $this->as('admin@demo.test')->withBody('role=tenant_staff')->patch("api/v1/admin/tenants/{$nusantara}/members/{$owner}");
        $result->assertStatus(422);
        $this->assertStringContainsString('minimal satu Owner', $result->getJSON());
    }

    public function testCannotTouchMembersOfAnotherTenant(): void
    {
        $arunikaOwner = $this->memberId('owner2@demo.test', 'arunika-logistics');
        $this->as('owner@demo.test', 'nusantara-digital')->withBody('role=tenant_staff')->patch("api/v1/office/members/{$arunikaOwner}")->assertStatus(404);
        $this->as('owner@demo.test', 'nusantara-digital')->delete("api/v1/office/members/{$arunikaOwner}")->assertStatus(404);
    }

    public function testDisabledMemberLosesAccessImmediately(): void
    {
        $staff = $this->memberId('staff@demo.test', 'nusantara-digital');
        $this->as('owner@demo.test', 'nusantara-digital')->withBody('status=disabled')->patch("api/v1/office/members/{$staff}")->assertStatus(200);
        $this->as('staff@demo.test', 'nusantara-digital')->get('api/v1/office')->assertStatus(403);
    }

    public function testExpiredAndReissuedInvitations(): void
    {
        $result = $this->as('owner@demo.test', 'nusantara-digital')->post('api/v1/office/members', ['email' => 'late@example.com', 'role' => 'tenant_staff']);
        $first  = $this->tokenFrom($this->data($result)['invite_url']);
        $id     = $this->data($result)['member']['id'];

        $second = $this->tokenFrom($this->data($this->as('owner@demo.test', 'nusantara-digital')->post("api/v1/office/members/{$id}/reinvite"))['invite_url']);
        $this->as(null)->get("invite/{$first}")->assertSee('Tautan tidak berlaku');

        Database::connect()->table('tenant_members')->where('id', $id)->update(['invite_expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->as(null)->get("invite/{$second}")->assertSee('Tautan tidak berlaku');
    }

    public function testDuplicateInviteIsRejected(): void
    {
        $this->as('owner@demo.test', 'nusantara-digital')->post('api/v1/office/members', ['email' => 'staff@demo.test', 'role' => 'tenant_staff'])->assertStatus(422);
    }
}
