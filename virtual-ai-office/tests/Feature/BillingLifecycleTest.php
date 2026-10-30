<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Payment\Tripay\TripayClient;
use App\Services\Payment\ChannelService;
use App\Services\Payment\ReconciliationService;
use App\Services\SubscriptionLifecycleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Throttle\ThrottlerInterface;
use Config\Database;
use Config\Services;
use Tests\Support\FakeTripay;

/** Renewal, past_due / expiry, reconciliation, invoice PDF and notifications. */
final class BillingLifecycleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const CSRF = 'test-csrf-token-0123456789abcdef';
    private const KEY  = 'test-private-key';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = DatabaseSeeder::class;

    private FakeTripay $fake;
    private int $tenantId;
    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Services::tenantContext()->clear();
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
        $c = config('Tripay');
        [$c->mode, $c->merchantCode, $c->apiKey, $c->privateKey, $c->verifyWithApi, $c->callbackAllowedIps, $c->baseUrlOverride]
            = ['sandbox', 'T0001', 'DEV-key', self::KEY, true, '', ''];
        $this->fake = new FakeTripay();
        Services::injectMock('tripay', new TripayClient($c, $this->fake));

        $db             = Database::connect();
        $this->tenantId = (int) $db->table('tenants')->where('slug', 'nusantara-digital')->get()->getRow()->id;
        $this->owner    = ['user_id' => (int) $db->table('users')->where('email', 'owner@demo.test')->get()->getRow()->id, 'active_tenant_id' => $this->tenantId];
    }

    private function client(array $session): static
    {
        Services::tenantContext()->clear();

        return $this->withSession($session + ['csrf_token' => self::CSRF])->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function sub(): array
    {
        return Database::connect()->table('tenant_subscriptions')->where('tenant_id', $this->tenantId)->orderBy('id', 'DESC')->get(1)->getRowArray();
    }

    private function setSub(array $values): void
    {
        Database::connect()->table('tenant_subscriptions')->where('id', $this->sub()['id'])->update($values);
    }

    private function renewalInvoices(?string $status = null): array
    {
        $q = Database::connect()->table('invoices')->where('tenant_id', $this->tenantId)->where('kind', 'renewal');
        if ($status) {
            $q->where('status', $status);
        }

        return $q->orderBy('id')->get()->getResultArray();
    }

    /** Pays an invoice through the API + a signed PAID callback. */
    private function payInvoice(int $invoiceId, bool $sendCallback = true): array
    {
        $this->fake->responses['merchant/payment-channel'] = [['group' => 'Virtual Account', 'code' => 'BRIVA', 'name' => 'BRI VA', 'active' => true,
            'fee_customer' => ['flat' => 4250, 'percent' => 0], 'minimum_amount' => 10000, 'maximum_amount' => 50000000]];
        (new ChannelService())->sync();
        $this->fake->onCreate = static fn (array $f) => ['reference' => 'DEV-' . $f['merchant_ref'], 'merchant_ref' => $f['merchant_ref'],
            'amount' => $f['amount'] + 4250, 'fee_customer' => 4250, 'fee_merchant' => 0, 'pay_code' => '123', 'status' => 'UNPAID', 'expired_time' => $f['expired_time']];
        $this->client($this->owner)->post("api/v1/office/invoices/{$invoiceId}/pay", ['method' => 'BRIVA'])->assertStatus(200);
        $payment = Database::connect()->table('payments')->where('invoice_id', $invoiceId)->orderBy('id', 'DESC')->get(1)->getRowArray();

        if ($sendCallback) {
            $this->fake->responses['transaction/detail'] = ['merchant_ref' => $payment['merchant_ref'], 'status' => 'PAID'];
            $body = json_encode(['reference' => $payment['provider_reference'], 'merchant_ref' => $payment['merchant_ref'],
                'total_amount' => (int) $payment['amount'] + 4250, 'fee_customer' => 4250, 'amount_received' => (int) $payment['amount'], 'status' => 'PAID', 'paid_at' => time()]);
            $this->withBody($body)->withHeaders(['X-Callback-Signature' => hash_hmac('sha256', $body, self::KEY), 'X-Callback-Event' => 'payment_status'])
                ->post('webhooks/tripay')->assertStatus(200);
        }

        return $payment;
    }

    public function testRenewalInvoiceIsCreatedOnceAtCurrentPlanPriceAndPaymentExtendsFromEnd(): void
    {
        $endsAt = date('Y-m-d H:i:s', strtotime('+3 days'));
        $this->setSub(['status' => 'active', 'ends_at' => $endsAt, 'starts_at' => date('Y-m-d H:i:s', strtotime('-27 days'))]);
        Database::connect()->table('subscription_plans')->where('code', 'PROFESSIONAL')->update(['price' => 2750000]);

        $service = new SubscriptionLifecycleService();
        $this->assertSame(1, $service->run()['renewal_invoices']);
        $this->assertSame(0, $service->run()['renewal_invoices'], 'idempotent');

        $invoice = $this->renewalInvoices('unpaid')[0];
        $this->assertSame(2750000, (int) $invoice['total']);
        $this->assertSame($endsAt, $invoice['due_at']);

        $this->payInvoice((int) $invoice['id']);
        $sub = $this->sub();
        $this->assertSame('active', $sub['status']);
        $this->assertSame(date('Y-m-d H:i:s', strtotime("{$endsAt} +30 days")), $sub['ends_at']);
    }

    public function testPastDueKeepsAccessThenExpiresAndTenantCanRenewAfterwards(): void
    {
        $this->setSub(['status' => 'active', 'ends_at' => date('Y-m-d H:i:s', time() - 3600)]);
        (new SubscriptionLifecycleService())->run();

        $this->assertSame('past_due', $this->sub()['status']);
        $this->assertCount(1, $this->renewalInvoices('unpaid'));
        $this->client($this->owner)->get('api/v1/office')->assertStatus(200); // grace period

        // Grace period (3 days) over.
        $this->setSub(['ends_at' => date('Y-m-d H:i:s', strtotime('-4 days'))]);
        Database::connect()->table('invoices')->where('tenant_id', $this->tenantId)->where('kind', 'renewal')
            ->update(['due_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $this->assertSame(1, (new SubscriptionLifecycleService())->run()['expired']);

        $db = Database::connect();
        $this->assertSame('expired', $this->sub()['status']);
        $this->assertSame('expired', $db->table('tenants')->where('id', $this->tenantId)->get()->getRow()->status);
        $this->assertSame('expired', $this->renewalInvoices()[0]['status']);
        $this->client($this->owner)->get('api/v1/office')->assertStatus(402);
        $this->assertSame(16, $db->table('ai_agents')->where('tenant_id', $this->tenantId)->countAllResults(), 'data kept');

        // The owner can still log in, request a renewal and pay it.
        $this->client($this->owner)->get('api/v1/office/billing')->assertStatus(200);
        $renew = json_decode($this->client($this->owner)->post('api/v1/office/subscription/renew')->getJSON(), true)['data']['invoice'];
        $this->payInvoice($renew['id']);

        $this->assertSame('active', $this->sub()['status']);
        $this->assertEqualsWithDelta(strtotime('+30 days'), strtotime($this->sub()['ends_at']), 120);
        $this->assertSame('active', $db->table('tenants')->where('id', $this->tenantId)->get()->getRow()->status);
        $this->client($this->owner)->get('api/v1/office')->assertStatus(200);
    }

    public function testPayingWhilePastDueContinuesFromOldEndDate(): void
    {
        $oldEnd = date('Y-m-d H:i:s', strtotime('-1 day'));
        $this->setSub(['status' => 'active', 'ends_at' => $oldEnd]);
        (new SubscriptionLifecycleService())->run();
        $this->payInvoice((int) $this->renewalInvoices('unpaid')[0]['id']);

        $this->assertSame(date('Y-m-d H:i:s', strtotime("{$oldEnd} +30 days")), $this->sub()['ends_at']);
    }

    public function testExpiredTrialLocksOffice(): void
    {
        $this->setSub(['status' => 'trial', 'ends_at' => date('Y-m-d H:i:s', time() - 60)]);
        (new SubscriptionLifecycleService())->run();
        $this->assertSame('expired', $this->sub()['status']);
        $this->client($this->owner)->get('api/v1/office')->assertStatus(402);
    }

    public function testReconciliationRecoversMissedCallbackExactlyOnce(): void
    {
        $this->setSub(['status' => 'active', 'ends_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
        (new SubscriptionLifecycleService())->run();
        $invoice = $this->renewalInvoices('unpaid')[0];
        $payment = $this->payInvoice((int) $invoice['id'], false); // callback "lost"

        $db = Database::connect();
        $db->table('payments')->where('id', $payment['id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 900)]);
        $this->fake->responses['transaction/detail'] = ['reference' => $payment['provider_reference'], 'merchant_ref' => $payment['merchant_ref'],
            'status' => 'PAID', 'amount' => (int) $payment['amount'] + 4250, 'fee_customer' => 4250, 'paid_at' => time()];

        $this->assertSame(['paid' => 1], (new ReconciliationService())->run());
        $this->assertSame('PAID', $db->table('payments')->where('id', $payment['id'])->get()->getRow()->status);
        $this->assertSame('paid', $db->table('invoices')->where('id', $invoice['id'])->get()->getRow()->status);
        $endsAt = $this->sub()['ends_at'];

        $this->assertSame([], (new ReconciliationService())->run());
        $this->assertSame($endsAt, $this->sub()['ends_at']);
    }

    public function testReconciliationRejectsAmountMismatch(): void
    {
        $this->setSub(['status' => 'active', 'ends_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
        (new SubscriptionLifecycleService())->run();
        $payment = $this->payInvoice((int) $this->renewalInvoices('unpaid')[0]['id'], false);
        Database::connect()->table('payments')->where('id', $payment['id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 900)]);
        $this->fake->responses['transaction/detail'] = ['merchant_ref' => $payment['merchant_ref'], 'status' => 'PAID', 'amount' => 1000, 'fee_customer' => 0];

        $this->assertSame(['rejected' => 1], (new ReconciliationService())->run());
        $this->assertSame('UNPAID', Database::connect()->table('payments')->where('id', $payment['id'])->get()->getRow()->status);
    }

    public function testInvoicePdfIsScopedToOwnersAndAdmins(): void
    {
        $this->setSub(['status' => 'active', 'ends_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
        (new SubscriptionLifecycleService())->run();
        $id = (int) $this->renewalInvoices('unpaid')[0]['id'];

        $pdf = $this->client($this->owner)->get("api/v1/office/invoices/{$id}/pdf");
        $pdf->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $pdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->response()->getBody());

        $db    = Database::connect();
        $staff = ['user_id' => (int) $db->table('users')->where('email', 'staff@demo.test')->get()->getRow()->id, 'active_tenant_id' => $this->tenantId];
        $this->client($staff)->get("api/v1/office/invoices/{$id}/pdf")->assertStatus(403);
        $other = ['user_id' => (int) $db->table('users')->where('email', 'owner2@demo.test')->get()->getRow()->id,
            'active_tenant_id' => (int) $db->table('tenants')->where('slug', 'arunika-logistics')->get()->getRow()->id];
        $this->client($other)->get("api/v1/office/invoices/{$id}/pdf")->assertStatus(404);
    }

    public function testBillingNotificationsReachOwnersOnlyAndCanBeMarkedRead(): void
    {
        $this->setSub(['status' => 'active', 'ends_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
        (new SubscriptionLifecycleService())->run();

        $owner = json_decode($this->client($this->owner)->get('api/v1/office/notifications')->getJSON(), true)['data'];
        $this->assertSame(1, $owner['unread']);
        $this->assertSame('invoice.renewal', $owner['notifications'][0]['type']);

        $db    = Database::connect();
        $staff = ['user_id' => (int) $db->table('users')->where('email', 'staff@demo.test')->get()->getRow()->id, 'active_tenant_id' => $this->tenantId];
        $this->assertSame([], json_decode($this->client($staff)->get('api/v1/office/notifications')->getJSON(), true)['data']['notifications']);
        $other = ['user_id' => (int) $db->table('users')->where('email', 'owner2@demo.test')->get()->getRow()->id,
            'active_tenant_id' => (int) $db->table('tenants')->where('slug', 'arunika-logistics')->get()->getRow()->id];
        $this->assertSame([], json_decode($this->client($other)->get('api/v1/office/notifications')->getJSON(), true)['data']['notifications']);

        $this->client($this->owner)->post('api/v1/office/notifications/read')->assertStatus(200);
        $this->assertSame(0, json_decode($this->client($this->owner)->get('api/v1/office/notifications')->getJSON(), true)['data']['unread']);
    }
}
