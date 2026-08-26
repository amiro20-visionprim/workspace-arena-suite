<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\Payment;
use App\Domains\Platform\Models\Plan;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Services\PlatformSettingsService;
use App\Models\User;
use Database\Seeders\PlatformBillingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * جریان کامل پرداخت زرین‌پال (فاز F2) — با merchant_id واقعی‌شکل و Http::fake:
 *
 *   پرچم خاموش → pay مسدود است
 *   پرچم روشن + initiate زرین‌پال (code=100 + authority)
 *   → redirect به StartPay
 *   → بازگشت callback با authority
 *   → verify زرین‌پال (code=100)
 *   → payment = paid + subscription فعال + رد حسابرسی
 */
class ZarinpalPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformBillingSeeder::class);

        config(['services.zarinpal.merchant_id' => 'test-merchant-uuid']);

        $this->org = Organization::query()->create([
            'public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'pay-'.Str::lower(Str::random(5)), 'status' => 'active',
        ]);
        $this->admin = User::factory()->create();
        // مسیرهای /platform فقط برای سوپرادمین باز هستند
        $superRole = Role::query()->firstOrCreate(['key' => 'super-admin'], ['name' => 'Super Admin', 'is_system' => true]);
        Membership::query()->create([
            'organization_id' => $this->org->id, 'user_id' => $this->admin->id,
            'role_id' => $superRole->id, 'status' => 'active',
        ]);

        $plan = Plan::query()->where('key', 'growth')->firstOrFail();
        $subscription = Subscription::query()->create([
            'organization_id' => $this->org->id, 'plan_id' => $plan->id,
            'status' => 'trialing', 'trial_ends_at' => now()->addDays(3),
        ]);
        $this->payment = Payment::query()->create([
            'organization_id' => $this->org->id,
            'subscription_id' => $subscription->id,
            'amount' => 35_000_000, // IRT → زرین‌پال ریالی: 3,500,000
            'method' => 'zarinpal', 'status' => Payment::STATUS_PENDING,
            'reference' => (string) Str::ulid(),
        ]);
    }

    public function test_full_zarinpal_flow_from_initiate_to_active_subscription(): void
    {
        // پرچم روشن
        app(PlatformSettingsService::class)->set('payments_enabled', 'true');

        $authority = 'A0000000000000000000000000000pay1';

        Http::fake([
            'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => $authority],
            ], 200),
            'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 1234567],
            ], 200),
        ]);

        // ۱) شروع پرداخت → باید به درگاه StartPay ریدایرکت شود
        $start = $this->actingAs($this->admin)
            ->post("/platform/payments/{$this->payment->id}/pay/zarinpal");
        $start->assertRedirect("https://payment.zarinpal.com/pg/StartPay/{$authority}");

        $this->payment->refresh();
        Assert::assertSame($authority, $this->payment->gateway_transaction_id);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'payment/request.json')
            && $request['amount'] === 3_500_000); // تبدیل IRT→ریال

        // ۲) بازگشت از درگاه
        $callback = $this->get("/platform/payments/callback/zarinpal/{$authority}?Authority={$authority}&Status=OK");
        $callback->assertOk();

        // ۳) نتیجه: پرداخت موفق + اشتراک فعال تا پایان دوره + حسابرسی
        $this->payment->refresh();
        Assert::assertSame(Payment::STATUS_PAID, $this->payment->status);
        Assert::assertNotNull($this->payment->paid_at);

        $subscription = $this->payment->subscription()->firstOrFail();
        Assert::assertSame('active', $subscription->status);

        Assert::assertTrue(
            DB::table('audit_logs')->where('action', 'platform.payment.initiated')->exists(),
            'رد حسابرسی شروع پرداخت',
        );
        Assert::assertTrue(
            DB::table('audit_logs')->where('action', 'platform.payment.verified')->exists()
            || DB::table('audit_logs')->where('action', 'platform.payment.marked_paid')->exists(),
            'رد حسابرسی تأیید پرداخت',
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'payment/verify.json'));
    }

    public function test_payments_are_blocked_while_feature_flag_is_off(): void
    {
        // پرچم پیش‌فرض خاموش است
        $response = $this->actingAs($this->admin)
            ->post("/platform/payments/{$this->payment->id}/pay/zarinpal");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->payment->refresh();
        Assert::assertSame(Payment::STATUS_PENDING, $this->payment->status, 'پرداخت نباید شروع شود');
        Assert::assertNull($this->payment->gateway_transaction_id);
        Http::assertNothingSent();
    }
}
