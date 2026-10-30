<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use App\Services\ReservationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Throttle\ThrottlerInterface;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

/** Self-service registration: reservation + pending subscription + unpaid invoice, and expiry. */
final class RegistrationTest extends CIUnitTestCase
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
        // Rate limiting is covered elsewhere; here every request must reach the controller.
        Services::injectMock('throttler', new class () implements ThrottlerInterface {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                return true;
            }

            public function getTokenTime(): int
            {
                return 0;
            }
        });
        Services::tenantContext()->clear();
    }

    private function client(array $session = []): static
    {
        Services::tenantContext()->clear();

        return $this->withSession($session + ['csrf_token' => self::CSRF])
            ->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'company_name' => 'Garuda Analytics', 'industry' => 'Data', 'brand_color' => '#f59e0b',
            'plan_code' => 'BUSINESS', 'floor_number' => '4',
            'owner_name' => 'Ayu Lestari', 'email' => 'ayu@garuda.test', 'password' => 'RahasiaKuat#1', 'password_confirm' => 'RahasiaKuat#1',
        ];
    }

    private function office(int $floor): array
    {
        return Database::connect()->table('offices o')->select('o.*')->join('floors f', 'f.id = o.floor_id')
            ->where('f.floor_number', $floor)->get()->getRowArray();
    }

    public function testGuestRegistrationReservesOfficeAndCreatesUnpaidInvoice(): void
    {
        $this->client()->post('api/v1/register', $this->form())->assertStatus(201);

        $db     = Database::connect();
        $tenant = $db->table('tenants')->where('slug', 'garuda-analytics')->get()->getRowArray();
        $office = $this->office(4);
        $this->assertSame('pending', $tenant['status']);
        $this->assertSame('reserved', $office['status']);
        $this->assertSame((int) $tenant['id'], (int) $office['tenant_id']);
        $this->assertNotNull($office['reserved_until']);

        $sub = $db->table('tenant_subscriptions')->where('tenant_id', $tenant['id'])->get()->getRowArray();
        $this->assertSame('pending', $sub['status']);
        $this->assertNull($sub['starts_at']);
        $invoice = $db->table('invoices')->where('tenant_id', $tenant['id'])->get()->getRowArray();
        $this->assertSame('unpaid', $invoice['status']);
        $this->assertSame(899000, (int) $invoice['total']);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-[0-9A-F]{6}$/', $invoice['invoice_number']);
        $this->assertSame(0, $db->table('payments')->countAllResults(), 'no payment rows may be fabricated');

        // Business plan: 4 common + 5 operational rooms enabled, IT room locked.
        $this->assertSame(9, $db->table('rooms')->where('tenant_id', $tenant['id'])->where('is_enabled', 1)->countAllResults());

        // The new owner is logged in, sees the invoice, but cannot use the office yet.
        $session = ['user_id' => (int) $db->table('users')->where('email', 'ayu@garuda.test')->get()->getRow()->id, 'active_tenant_id' => (int) $tenant['id']];
        $billing = json_decode($this->client($session)->get('api/v1/office/billing')->getJSON(), true)['data'];
        $this->assertSame($invoice['invoice_number'], $billing['invoices'][0]['invoice_number']);
        $this->assertSame('reserved', $billing['office']['status']);
        $this->client($session)->get('api/v1/office')->assertStatus(402);

        // Billing is for owners/admins only.
        $staff = ['user_id' => (int) $db->table('users')->where('email', 'staff@demo.test')->get()->getRow()->id,
            'active_tenant_id' => (int) $db->table('tenants')->where('slug', 'nusantara-digital')->get()->getRow()->id];
        $this->client($staff)->get('api/v1/office/billing')->assertStatus(403);

        // Public directory: reserved, company not shown until active.
        $floors = json_decode($this->get('api/v1/building')->getJSON(), true)['data']['floors'];
        $this->assertSame('reserved', $floors[4]['office']['status']);
        $this->assertNull($floors[4]['tenant']);
    }

    public function testSameFloorCannotBeReservedTwice(): void
    {
        $this->client()->post('api/v1/register', $this->form())->assertStatus(201);
        $this->client()->post('api/v1/register', $this->form(['email' => 'other@x.test', 'company_name' => 'Other']))->assertStatus(409);
        $this->client()->post('api/v1/register', $this->form(['email' => 'other@x.test', 'floor_number' => '7']))->assertStatus(409);
    }

    public function testCustomOrUnknownPlansAndExistingEmailsAreRejected(): void
    {
        $this->client()->post('api/v1/register', $this->form(['plan_code' => 'ENTERPRISE']))->assertStatus(422);
        $this->client()->post('api/v1/register', $this->form(['plan_code' => 'NOPE']))->assertStatus(422);
        $this->client()->post('api/v1/register', $this->form(['email' => 'owner@demo.test']))->assertStatus(409);
        $this->client()->post('api/v1/register', $this->form(['password_confirm' => 'different']))->assertStatus(400);
        $this->assertSame('available', $this->office(4)['status']);
    }

    public function testLoggedInUserRegistersAnotherCompanyWithoutNewAccount(): void
    {
        $db    = Database::connect();
        $owner = (int) $db->table('users')->where('email', 'owner2@demo.test')->get()->getRow()->id;
        $users = $db->table('users')->countAllResults();

        $this->client(['user_id' => $owner])->post('api/v1/register', [
            'company_name' => 'Arunika Cargo', 'plan_code' => 'STARTER', 'floor_number' => '6',
        ])->assertStatus(201);

        $this->assertSame($users, $db->table('users')->countAllResults());
        $tenantId = (int) $db->table('tenants')->where('slug', 'arunika-cargo')->get()->getRow()->id;
        $this->assertSame(1, $db->table('tenant_members')->where('tenant_id', $tenantId)->where('user_id', $owner)->countAllResults());
    }

    public function testOverdueReservationIsReleased(): void
    {
        $this->client()->post('api/v1/register', $this->form())->assertStatus(201);
        $db       = Database::connect();
        $tenantId = (int) $db->table('tenants')->where('slug', 'garuda-analytics')->get()->getRow()->id;

        $this->assertSame(0, (new ReservationService())->expireOverdue(), 'not yet due');

        $db->table('offices')->where('tenant_id', $tenantId)->update(['reserved_until' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame(1, (new ReservationService())->expireOverdue());

        $office = $this->office(4);
        $this->assertSame('available', $office['status']);
        $this->assertNull($office['tenant_id']);
        $this->assertSame(0, $db->table('rooms')->where('tenant_id', $tenantId)->countAllResults());
        $this->assertSame('cancelled', $db->table('tenant_subscriptions')->where('tenant_id', $tenantId)->get()->getRow()->status);
        $this->assertSame('expired', $db->table('invoices')->where('tenant_id', $tenantId)->get()->getRow()->status);
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'reservation.expired')->countAllResults());

        // Floor can be reserved again.
        $this->client()->post('api/v1/register', $this->form(['email' => 'new@x.test', 'company_name' => 'Next Co']))->assertStatus(201);
    }
}
