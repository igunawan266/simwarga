<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Payment\Tripay\HttpTransport;
use App\Libraries\Payment\Tripay\TripayClient;
use App\Libraries\Payment\Tripay\TripayException;
use App\Services\Payment\ChannelService;
use App\Services\ReservationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Throttle\ThrottlerInterface;
use Config\Database;
use Config\Services;

/**
 * Tripay integration without network access: a fake transport plays Tripay's API, and
 * callbacks are signed exactly as Tripay signs them (HMAC-SHA256 of the raw body).
 */
final class TripayPaymentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const CSRF        = 'test-csrf-token-0123456789abcdef';
    private const MERCHANT    = 'T0001';
    private const PRIVATE_KEY = 'test-private-key';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = DatabaseSeeder::class;

    private FakeTripay $fake;

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

        $config                = config('Tripay');
        $config->mode          = 'sandbox';
        $config->merchantCode  = self::MERCHANT;
        $config->apiKey        = 'DEV-test-api-key';
        $config->privateKey    = self::PRIVATE_KEY;
        $config->verifyWithApi = true;
        $config->callbackAllowedIps = '';
        $config->baseUrlOverride    = ''; // independent of any local .env

        $this->fake = new FakeTripay();
        Services::injectMock('tripay', new TripayClient($config, $this->fake));
    }

    // ------------------------------------------------------------------ helpers

    private function client(array $session = []): static
    {
        Services::tenantContext()->clear();

        return $this->withSession($session + ['csrf_token' => self::CSRF])
            ->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    /** Registers Garuda Analytics on floor 4 (Business, Rp 899.000) and returns its owner session + invoice. */
    private function registerTenant(): array
    {
        $this->client()->post('api/v1/register', [
            'company_name' => 'Garuda Analytics', 'plan_code' => 'BUSINESS', 'floor_number' => '4',
            'owner_name' => 'Ayu', 'email' => 'ayu@garuda.test', 'password' => 'RahasiaKuat#1', 'password_confirm' => 'RahasiaKuat#1',
        ])->assertStatus(201);
        $db       = Database::connect();
        $tenantId = (int) $db->table('tenants')->where('slug', 'garuda-analytics')->get()->getRow()->id;

        return [
            'session' => ['user_id' => (int) $db->table('users')->where('email', 'ayu@garuda.test')->get()->getRow()->id, 'active_tenant_id' => $tenantId],
            'invoice' => $db->table('invoices')->where('tenant_id', $tenantId)->get()->getRowArray(),
            'tenant'  => $tenantId,
        ];
    }

    private function syncChannels(): void
    {
        $this->fake->responses['merchant/payment-channel'] = [
            ['group' => 'Virtual Account', 'code' => 'BRIVA', 'name' => 'BRI Virtual Account', 'type' => 'DIRECT', 'active' => true,
                'fee_customer' => ['flat' => 4250, 'percent' => 0], 'minimum_amount' => 10000, 'maximum_amount' => 5000000, 'icon_url' => 'https://example.test/briva.png'],
            ['group' => 'E-Wallet', 'code' => 'QRIS', 'name' => 'QRIS', 'type' => 'DIRECT', 'active' => true,
                'fee_customer' => ['flat' => 750, 'percent' => 0.7], 'minimum_amount' => 1000, 'maximum_amount' => 5000000, 'icon_url' => null],
        ];
        (new ChannelService())->sync();
    }

    /** Pays the invoice with BRIVA (fee 4.250 charged to the customer) and returns the payment row. */
    private function createPayment(array $t): array
    {
        $this->syncChannels();
        $this->fake->onCreate = static fn (array $form) => [
            'reference' => 'DEV-T0001' . random_int(100000, 999999), 'merchant_ref' => $form['merchant_ref'], 'payment_name' => 'BRI Virtual Account',
            'amount' => $form['amount'] + 4250, 'fee_merchant' => 0, 'fee_customer' => 4250, 'pay_code' => '57585748548596587',
            'checkout_url' => 'https://tripay.co.id/checkout/DEV', 'status' => 'UNPAID', 'expired_time' => $form['expired_time'],
            'instructions' => [['title' => 'Internet Banking', 'steps' => ['Login', 'Bayar']]],
        ];
        $this->client($t['session'])->post("api/v1/office/invoices/{$t['invoice']['id']}/pay", ['method' => 'BRIVA'])->assertStatus(200);

        return Database::connect()->table('payments')->where('invoice_id', $t['invoice']['id'])->orderBy('id', 'DESC')->get(1)->getRowArray();
    }

    private function sendCallback(array $payment, array $overrides = [], ?string $signature = null)
    {
        $body = json_encode($overrides + [
            'reference' => $payment['provider_reference'], 'merchant_ref' => $payment['merchant_ref'],
            'payment_method' => 'BRI Virtual Account', 'payment_method_code' => 'BRIVA',
            'total_amount' => (int) $payment['amount'] + 4250, 'fee_merchant' => 0, 'fee_customer' => 4250,
            'total_fee' => 4250, 'amount_received' => (int) $payment['amount'], 'is_closed_payment' => 1,
            'status' => 'PAID', 'paid_at' => time(), 'note' => null,
        ]);
        $this->fake->responses['transaction/detail'] ??= ['reference' => $payment['provider_reference'], 'merchant_ref' => $payment['merchant_ref'], 'status' => 'PAID'];

        return $this->withBody($body)->withHeaders([
            'X-Callback-Signature' => $signature ?? hash_hmac('sha256', $body, self::PRIVATE_KEY),
            'X-Callback-Event'     => 'payment_status',
            'Content-Type'         => 'application/json',
        ])->post('webhooks/tripay');
    }

    // ------------------------------------------------------------------ tests

    public function testChannelSyncStoresCustomerFeesAndDeactivatesRemovedChannels(): void
    {
        $this->syncChannels();
        $db    = Database::connect();
        $briva = $db->table('payment_channels')->where('code', 'BRIVA')->get()->getRowArray();
        $this->assertSame(4250, (int) $briva['fee_flat']);
        $this->assertSame('Virtual Account', $briva['group_name']);
        $this->assertSame('Bearer DEV-test-api-key', $this->fake->requests[0]['headers']['Authorization']);
        $this->assertStringStartsWith('https://tripay.co.id/api-sandbox/', $this->fake->requests[0]['url']);

        $this->fake->responses['merchant/payment-channel'] = [$this->fake->responses['merchant/payment-channel'][1]];
        (new ChannelService())->sync();
        $this->assertSame(0, (int) $db->table('payment_channels')->where('code', 'BRIVA')->get()->getRow()->is_active);
    }

    public function testPayCreatesClosedTransactionWithOfficialSignatureAndReusesIt(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);
        $create  = array_values(array_filter($this->fake->requests, static fn ($r) => str_ends_with($r['url'], 'transaction/create')))[0];

        $ref = $t['invoice']['invoice_number'] . '-1';
        $this->assertSame($ref, $create['form']['merchant_ref']);
        $this->assertSame(899000, $create['form']['amount']);
        $this->assertSame(hash_hmac('sha256', self::MERCHANT . $ref . '899000', self::PRIVATE_KEY), $create['form']['signature']);
        $this->assertSame('BRIVA', $create['form']['method']);
        $this->assertSame(899000, array_sum(array_map(static fn ($i) => $i['price'] * $i['quantity'], $create['form']['order_items'])));
        $this->assertLessThanOrEqual(strtotime($t['invoice']['due_at']), $create['form']['expired_time']);

        $this->assertSame('UNPAID', $payment['status']);
        $this->assertSame('57585748548596587', $payment['pay_code']);
        $this->assertSame('unpaid', Database::connect()->table('invoices')->where('id', $t['invoice']['id'])->get()->getRow()->status);

        // Same method again: no second Tripay transaction.
        $before = count($this->fake->requests);
        $this->client($t['session'])->post("api/v1/office/invoices/{$t['invoice']['id']}/pay", ['method' => 'BRIVA'])->assertStatus(200);
        $this->assertCount($before, $this->fake->requests);
    }

    public function testPaidCallbackActivatesSubscriptionExactlyOnce(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);
        $db      = Database::connect();

        $first = $this->sendCallback($payment);
        $first->assertStatus(200);
        $this->assertSame(['success' => true], json_decode($first->getJSON(), true));

        $sub = $db->table('tenant_subscriptions')->where('tenant_id', $t['tenant'])->get()->getRowArray();
        $this->assertSame('active', $sub['status']);
        $this->assertEqualsWithDelta(strtotime('+30 days'), strtotime($sub['ends_at']), 120);
        $this->assertSame('paid', $db->table('invoices')->where('id', $t['invoice']['id'])->get()->getRow()->status);
        $this->assertSame('PAID', $db->table('payments')->where('id', $payment['id'])->get()->getRow()->status);
        $this->assertSame('active', $db->table('tenants')->where('id', $t['tenant'])->get()->getRow()->status);
        $office = $db->table('offices')->where('tenant_id', $t['tenant'])->get()->getRowArray();
        $this->assertSame('occupied', $office['status']);
        $this->assertNull($office['reserved_until']);

        // The owner can now enter the office.
        $this->client($t['session'])->get('api/v1/office')->assertStatus(200);

        // Replays (identical body, and a re-signed different body) never extend twice.
        $this->sendCallback($payment)->assertStatus(200);
        $this->sendCallback($payment, ['note' => 'retry'])->assertStatus(200);
        $this->assertSame($sub['ends_at'], $db->table('tenant_subscriptions')->where('id', $sub['id'])->get()->getRow()->ends_at);
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'payment.paid')->countAllResults());
        $this->assertSame(1, $db->table('payment_callbacks')->where('process_status', 'processed')->countAllResults());
    }

    public function testInvalidSignatureIsRejectedAndNothingChanges(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);

        $this->sendCallback($payment, [], 'forged-signature')->assertStatus(400);
        $this->sendCallback($payment, [], hash_hmac('sha256', 'other body', self::PRIVATE_KEY))->assertStatus(400);

        $db = Database::connect();
        $this->assertSame('UNPAID', $db->table('payments')->where('id', $payment['id'])->get()->getRow()->status);
        $this->assertSame('pending', $db->table('tenant_subscriptions')->where('tenant_id', $t['tenant'])->get()->getRow()->status);
        $this->assertSame(2, $db->table('payment_callbacks')->where('signature_valid', 0)->where('process_status', 'rejected')->countAllResults());
    }

    public function testAmountMismatchIsRejected(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);

        $this->sendCallback($payment, ['total_amount' => 10000])->assertStatus(400);
        $this->assertSame('UNPAID', Database::connect()->table('payments')->where('id', $payment['id'])->get()->getRow()->status);
    }

    public function testCallbackNotConfirmedByApiIsRejected(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);
        $this->fake->responses['transaction/detail'] = ['reference' => $payment['provider_reference'], 'merchant_ref' => $payment['merchant_ref'], 'status' => 'UNPAID'];

        $this->sendCallback($payment)->assertStatus(400);
        $this->assertSame('pending', Database::connect()->table('tenant_subscriptions')->where('tenant_id', $t['tenant'])->get()->getRow()->status);
    }

    public function testApiOutageReturns503AndTheRetrySucceeds(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);
        $this->fake->fail['transaction/detail'] = true;

        $body = json_encode(['reference' => $payment['provider_reference'], 'merchant_ref' => $payment['merchant_ref'],
            'total_amount' => (int) $payment['amount'] + 4250, 'fee_customer' => 4250, 'status' => 'PAID', 'paid_at' => time()]);
        $send = fn () => $this->withBody($body)->withHeaders([
            'X-Callback-Signature' => hash_hmac('sha256', $body, self::PRIVATE_KEY), 'X-Callback-Event' => 'payment_status',
        ])->post('webhooks/tripay');

        $send()->assertStatus(503);
        $this->fake->fail['transaction/detail'] = false;
        $this->fake->responses['transaction/detail'] = ['merchant_ref' => $payment['merchant_ref'], 'status' => 'PAID'];
        $send()->assertStatus(200);
        $this->assertSame('PAID', Database::connect()->table('payments')->where('id', $payment['id'])->get()->getRow()->status);
    }

    public function testExpiredCallbackKeepsInvoiceOpenForAnotherMethod(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);

        $this->sendCallback($payment, ['status' => 'EXPIRED'])->assertStatus(200);
        $db = Database::connect();
        $this->assertSame('EXPIRED', $db->table('payments')->where('id', $payment['id'])->get()->getRow()->status);
        $this->assertSame('unpaid', $db->table('invoices')->where('id', $t['invoice']['id'])->get()->getRow()->status);
    }

    public function testLatePaymentReclaimsOfficeIfStillFreeOtherwiseFlagsForReview(): void
    {
        $t       = $this->registerTenant();
        $payment = $this->createPayment($t);
        $db      = Database::connect();

        $db->table('offices')->where('tenant_id', $t['tenant'])->update(['reserved_until' => date('Y-m-d H:i:s', time() - 60)]);
        (new ReservationService())->expireOverdue();
        $this->assertSame(0, $db->table('rooms')->where('tenant_id', $t['tenant'])->countAllResults());

        $this->sendCallback($payment)->assertStatus(200);
        $office = $db->table('offices o')->select('o.status, o.tenant_id')->join('floors f', 'f.id = o.floor_id')->where('f.floor_number', 4)->get()->getRowArray();
        $this->assertSame($t['tenant'], (int) $office['tenant_id']);
        $this->assertSame('occupied', $office['status']);
        $this->assertSame(10, $db->table('rooms')->where('tenant_id', $t['tenant'])->countAllResults());
        $this->assertStringContainsString('activated', $db->table('payment_callbacks')->where('process_status', 'processed')->get()->getRow()->process_message);
    }

    public function testGatewayNotConfiguredAndForeignInvoices(): void
    {
        $t = $this->registerTenant();

        // Another tenant's owner cannot see or pay this invoice.
        $db    = Database::connect();
        $other = ['user_id' => (int) $db->table('users')->where('email', 'owner@demo.test')->get()->getRow()->id,
            'active_tenant_id' => (int) $db->table('tenants')->where('slug', 'nusantara-digital')->get()->getRow()->id];
        $this->client($other)->get("api/v1/office/invoices/{$t['invoice']['id']}/channels")->assertStatus(404);
        $this->client($other)->post("api/v1/office/invoices/{$t['invoice']['id']}/pay", ['method' => 'BRIVA'])->assertStatus(404);

        config('Tripay')->apiKey = '';
        $this->client($t['session'])->get("api/v1/office/invoices/{$t['invoice']['id']}/channels")->assertStatus(503);
    }
}

/** Plays Tripay's API: canned responses per path, records every request. */
final class FakeTripay implements HttpTransport
{
    public array $requests  = [];
    public array $responses = [];
    public array $fail      = [];

    /** @var callable|null fn(array $form): array */
    public $onCreate;

    public function send(string $method, string $url, array $headers, array $query = [], array $form = []): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'query', 'form');
        $path             = preg_replace('#^https://tripay\.co\.id/api(-sandbox)?/#', '', $url);

        if (! empty($this->fail[$path])) {
            throw new TripayException('Tidak dapat terhubung ke Tripay: timeout');
        }
        if ($path === 'transaction/create' && $this->onCreate) {
            return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ($this->onCreate)($form)])];
        }
        if (! array_key_exists($path, $this->responses)) {
            return ['status' => 404, 'body' => json_encode(['success' => false, 'message' => 'Not found'])];
        }

        return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => $this->responses[$path]])];
    }
}
