<?php

namespace Tests\Feature\Platform;

use App\Models\Devotee;
use App\Models\DevoteeSubscription;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Support\Payments\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Subscriptions and payments: a plan switches on only when the gateway
 * confirms, exactly once, and the app is told the result.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = SubscriptionPlan::create([
            'code' => 'yatri-plus', 'name' => 'Yatri Plus', 'price_paise' => 4900, 'duration_days' => 30,
            'benefits' => ['no_ads' => true, 'memory_photos_per_visit' => 10],
        ]);

        Setting::set('payments_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_key_id', 'rzp_test_key');
        Setting::set('payments_razorpay_key_secret', 'rzp_secret', 'secret');
        Setting::set('payments_razorpay_webhook_secret', 'hook_secret', 'secret');
    }

    protected function devotee(): Devotee
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    public function test_plans_are_listed_with_what_the_app_may_do_on_each_platform(): void
    {
        $this->getJson('/api/v1/plans?platform=android')->assertOk()
            ->assertJsonPath('data.0.code', 'yatri-plus')
            ->assertJsonPath('data.0.price', '₹49')
            ->assertJsonPath('data.0.period', 'per month')
            ->assertJsonPath('meta.payments.enabled', true)
            ->assertJsonPath('meta.payments.gateways.0.code', 'razorpay');

        // Off on iOS by default: Apple requires its own purchase system.
        $this->getJson('/api/v1/plans?platform=ios')->assertJsonPath('meta.payments.enabled', false)
            ->assertJsonPath('meta.payments.available_elsewhere', true);
    }

    public function test_a_razorpay_checkout_pays_and_switches_the_plan_on(): void
    {
        $devotee = $this->devotee();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_ABC'])]);

        $checkout = $this->postJson('/api/v1/me/checkout', ['plan' => 'yatri-plus', 'platform' => 'android'])->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(4900, $payment->amount_paise);

        // The signed page renders Razorpay's checkout for the order.
        $this->get($checkout->json('data.checkout_url'))->assertOk()->assertSee('order_ABC')->assertSee('rzp_test_key');
        $this->get(route('pay.show', $payment))->assertForbidden(); // unsigned

        $signature = hash_hmac('sha256', 'order_ABC|pay_XYZ', 'rzp_secret');
        $this->post(route('pay.return', ['payment' => $payment, 'gateway' => 'razorpay']), [
            'razorpay_payment_id' => 'pay_XYZ', 'razorpay_order_id' => 'order_ABC', 'razorpay_signature' => $signature,
        ])->assertRedirect(route('pay.done', $payment));

        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->getJson('/api/v1/me/payments/'.$payment->uuid)->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.entitlements.no_ads', true)
            ->assertJsonPath('data.entitlements.memory_photos_per_visit', 10);
        $this->getJson('/api/v1/app/config?platform=android')->assertJsonPath('data.ads.enabled', false);
        $this->assertSame(1, $devotee->subscriptions()->count());
    }

    public function test_the_app_pays_razorpay_through_its_native_sdk(): void
    {
        $devotee = $this->devotee();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_SDK'])]);

        $checkout = $this->postJson('/api/v1/me/checkout', ['plan' => 'yatri-plus', 'platform' => 'android', 'mode' => 'sdk'])
            ->assertCreated()
            ->assertJsonPath('data.sdk.gateway', 'razorpay')
            ->assertJsonPath('data.sdk.key', 'rzp_test_key')
            ->assertJsonPath('data.sdk.order_id', 'order_SDK')
            ->assertJsonPath('data.sdk.amount_paise', 4900);
        // The web checkout stays available as a fallback.
        $this->assertNotEmpty($checkout->json('data.checkout_url'));
        $uuid = $checkout->json('data.payment.id');

        // What Razorpay's SDK hands the app on success, checked by signature.
        $this->postJson("/api/v1/me/payments/{$uuid}/confirm", [
            'razorpay_payment_id' => 'pay_SDK', 'razorpay_order_id' => 'order_SDK',
            'razorpay_signature' => hash_hmac('sha256', 'order_SDK|pay_SDK', 'rzp_secret'),
        ])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.entitlements.no_ads', true);

        $this->assertSame(1, $devotee->subscriptions()->count());
    }

    public function test_a_native_result_with_a_forged_signature_or_another_order_does_not_pay(): void
    {
        $this->devotee();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_SDK'])]);
        $uuid = $this->postJson('/api/v1/me/checkout', ['plan' => 'yatri-plus', 'mode' => 'sdk'])->json('data.payment.id');

        $this->postJson("/api/v1/me/payments/{$uuid}/confirm", [
            'razorpay_payment_id' => 'pay_SDK', 'razorpay_order_id' => 'order_OTHER', 'razorpay_signature' => 'x',
        ])->assertStatus(422);

        $this->postJson("/api/v1/me/payments/{$uuid}/confirm", [
            'razorpay_payment_id' => 'pay_SDK', 'razorpay_order_id' => 'order_SDK', 'razorpay_signature' => 'forged',
        ])->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(0, DevoteeSubscription::count());
    }

    public function test_cashfree_opens_natively_and_is_confirmed_with_cashfree(): void
    {
        $devotee = $this->devotee();
        Setting::set('payments_cashfree_enabled', '1', 'boolean');
        Setting::set('payments_cashfree_app_id', 'cf');
        Setting::set('payments_cashfree_secret_key', 'cfs', 'secret');
        Http::fake([
            'sandbox.cashfree.com/pg/orders' => Http::response(['payment_session_id' => 'sess_SDK']),
            'sandbox.cashfree.com/pg/orders/*' => Http::response(['order_status' => 'PAID', 'payment_session_id' => 'sess_SDK']),
        ]);

        $checkout = $this->postJson('/api/v1/me/checkout', ['plan' => 'yatri-plus', 'gateway' => 'cashfree', 'mode' => 'sdk'])
            ->assertCreated()
            ->assertJsonPath('data.sdk.gateway', 'cashfree')
            ->assertJsonPath('data.sdk.session_id', 'sess_SDK')
            ->assertJsonPath('data.sdk.environment', 'sandbox');

        // Falling back to the web page reuses the same Cashfree order.
        $this->get($checkout->json('data.checkout_url'))->assertOk()->assertSee('sess_SDK');
        Http::assertSentCount(2); // one order created, one looked up

        // Cashfree's SDK only says "verify this order": the server asks Cashfree.
        $this->postJson('/api/v1/me/payments/'.$checkout->json('data.payment.id').'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, $devotee->subscriptions()->count());
    }

    public function test_one_devotee_cannot_confirm_anothers_payment(): void
    {
        $this->devotee();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_SDK'])]);
        $uuid = $this->postJson('/api/v1/me/checkout', ['plan' => 'yatri-plus', 'mode' => 'sdk'])->json('data.payment.id');

        $this->devotee();
        $this->postJson("/api/v1/me/payments/{$uuid}/confirm")->assertNotFound();
    }

    public function test_a_forged_signature_does_not_pay(): void
    {
        $this->devotee();
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_ABC'])]);
        $this->postJson('/api/v1/me/checkout', ['plan' => 'yatri-plus'])->assertCreated();
        $payment = Payment::query()->firstOrFail();
        app(Payments::class)->gateway('razorpay')->start($payment);

        $this->post(route('pay.return', ['payment' => $payment, 'gateway' => 'razorpay']), [
            'razorpay_payment_id' => 'pay_XYZ', 'razorpay_signature' => 'forged',
        ]);

        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->assertSame(0, DevoteeSubscription::count());
    }

    public function test_the_webhook_and_the_return_together_start_one_subscription(): void
    {
        $devotee = $this->devotee();
        $payment = Payment::create(['devotee_id' => $devotee->id, 'subscription_plan_id' => $this->plan->id, 'gateway' => 'razorpay', 'amount_paise' => 4900, 'gateway_order_id' => 'order_1', 'status' => 'pending']);

        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_1']]]]);
        $call = fn (string $sig) => $this->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $sig], $body);

        $call('bad-signature')->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);

        $call(hash_hmac('sha256', $body, 'hook_secret'))->assertOk();
        $call(hash_hmac('sha256', $body, 'hook_secret'))->assertOk();
        app(Payments::class)->apply($payment, Payment::PAID);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pay_1', $payment->fresh()->gateway_payment_id);
        $this->assertSame(1, DevoteeSubscription::count());
    }

    public function test_renewing_early_adds_on_after_the_current_plan(): void
    {
        $devotee = Devotee::factory()->create();
        $payments = app(Payments::class);

        $first = $payments->startSubscription($devotee, $this->plan);
        $second = $payments->startSubscription($devotee, $this->plan);

        $this->assertTrue($second->starts_at->equalTo($first->ends_at));
        $this->assertEqualsWithDelta(60, now()->diffInDays($second->ends_at), 1);
    }

    public function test_phonepe_cashfree_and_payu_confirm_with_the_gateway(): void
    {
        $devotee = Devotee::factory()->create(['phone' => '9876543210']);
        Setting::set('payments_phonepe_client_id', 'pp');
        Setting::set('payments_phonepe_client_secret', 'pps', 'secret');
        Setting::set('payments_cashfree_app_id', 'cf');
        Setting::set('payments_cashfree_secret_key', 'cfs', 'secret');
        Setting::set('payments_payu_key', 'pukey');
        Setting::set('payments_payu_salt', 'pusalt', 'secret');

        Http::fake([
            '*/v1/oauth/token' => Http::response(['access_token' => 'tok']),
            '*/checkout/v2/pay' => Http::response(['orderId' => 'OMO1', 'redirectUrl' => 'https://mercury.phonepe.com/pay/1']),
            '*/checkout/v2/order/*/status' => Http::response(['state' => 'COMPLETED', 'paymentDetails' => [['transactionId' => 'T1']]]),
            'sandbox.cashfree.com/pg/orders' => Http::response(['payment_session_id' => 'sess_1']),
            'sandbox.cashfree.com/pg/orders/*' => Http::response(['order_status' => 'PAID']),
        ]);

        $payments = app(Payments::class);
        $make = fn (string $g) => Payment::create(['devotee_id' => $devotee->id, 'subscription_plan_id' => $this->plan->id, 'gateway' => $g, 'amount_paise' => 4900]);

        $phonepe = $make('phonepe');
        $this->assertSame('https://mercury.phonepe.com/pay/1', $payments->gateway('phonepe')->start($phonepe)['redirect']);
        $this->assertSame('paid', $payments->reconcile($phonepe)->status);

        $cashfree = $make('cashfree');
        $this->assertSame('sess_1', $payments->gateway('cashfree')->start($cashfree->load('devotee', 'plan'))['data']['session_id']);
        $this->assertSame('paid', $payments->reconcile($cashfree)->status);

        $payu = $make('payu');
        $start = $payments->gateway('payu')->start($payu->load('devotee', 'plan'));
        $f = $start['data']['fields'];
        $reverse = hash('sha512', implode('|', ['pusalt', 'success', '', '', '', '', '', '', '', '', '', '', $f['email'], $f['firstname'], $f['productinfo'], $f['amount'], $f['txnid'], 'pukey']));
        $this->post(route('pay.return', ['payment' => $payu->fresh(), 'gateway' => 'payu']), [
            'status' => 'success', 'txnid' => $f['txnid'], 'amount' => $f['amount'], 'productinfo' => $f['productinfo'],
            'firstname' => $f['firstname'], 'email' => $f['email'], 'mihpayid' => 'M1', 'hash' => $reverse,
        ]);
        $this->assertSame('paid', $payu->fresh()->status);

        $this->assertSame(3, $devotee->subscriptions()->count());
    }

    public function test_a_plan_raises_the_memory_photo_limit(): void
    {
        $devotee = Devotee::factory()->create();
        $this->assertSame(3, $devotee->entitlements()['memory_photos_per_visit']);

        app(Payments::class)->startSubscription($devotee, $this->plan);

        $this->assertSame(10, $devotee->entitlements()['memory_photos_per_visit']);
        $this->assertTrue($devotee->entitlements()['no_ads']);
    }

    public function test_checkout_is_refused_when_payments_are_off_for_the_platform(): void
    {
        $this->devotee();

        $this->postJson('/api/v1/me/checkout', ['plan' => 'yatri-plus', 'platform' => 'ios'])->assertForbidden();
        $this->assertSame(0, Payment::count());
    }
}
