<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Domains\Connector\Actions\CreatePairingToken;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * تست سرتاسری «مسیر پول» — کامل‌ترین زنجیرهٔ ارزش محصول در یک تست:
 *
 *   ثبت‌نام (OTP) ← ساخت سازمان (onboarding) ← مشتری ← پروژه ← سایت
 *   ← اتصال پلاگین وردپرس (pairing) ← پیکربندی سرویس AI
 *   ← تولید مقاله با هوش مصنوعی ← بازبینی و تأیید انسانی
 *   ← انتشار خودکار روی وردپرس ← رد حسابرسی (audit trail)
 *
 * اگر این تست قرمز شود، «مسیر پول» محصول شکسته است — فارغ از اینکه
 * کدام جزء مقصر است. هدف: هیچ changeای نتواند این زنجیره را بی‌صدا بشکند.
 */
class MoneyPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // ۱) پاسخ سرویس AI (مقالهٔ کاملِ پاس‌کنندهٔ گیت‌های کیفیت)
        // ۲) پاسخ پلاگین وردپرس به فرمان انتشار
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => $this->articleHtml()],
                ]],
                'usage' => ['prompt_tokens' => 220, 'completion_tokens' => 1400],
            ], 200),
            '*/wp-json/vision-prime/v1/commands' => Http::response([
                'ok' => true,
                'result' => ['post_id' => 4242, 'previous' => ['created' => true, 'post_id' => 4242], 'new_length' => 1200],
            ], 200),
        ]);
    }

    public function test_full_money_path_from_signup_to_wordpress_publish(): void
    {
        // ─────────── ۱) ثبت‌نام با کد یکبارمصرف ───────────
        $otp = $this->postJson('/register/otp', ['phone' => '09121234567']);
        $otp->assertOk()->assertJson(['sent' => true]);
        $code = (string) $otp->json('code');
        Assert::assertNotSame('', $code, 'در sandbox باید کد برگردد');

        $this->post('/register', [
            'name' => 'مدیر آژانس',
            'email' => 'owner@agency.ir',
            'phone' => '09121234567',
            'otp_code' => $code,
            'password' => 'StrongPass@2026',
            'password_confirmation' => 'StrongPass@2026',
            'terms' => true,
        ])->assertRedirect(route('app.onboarding'));

        $this->assertDatabaseHas('users', ['email' => 'owner@agency.ir', 'phone' => '09121234567']);
        $this->assertAuthenticated();

        // ─────────── ۲) ساخت سازمان (onboarding) ───────────
        $this->get('/app/onboarding')->assertOk();

        $this->post('/app/onboarding', ['name' => 'آژانس رشد دیجیتال'])
            ->assertRedirect(route('app.dashboard'));

        $orgId = (int) DB::table('organizations')->where('name', 'آژانس رشد دیجیتال')->value('id');
        Assert::assertGreaterThan(0, $orgId);
        Assert::assertSame(
            'agency-admin',
            (string) DB::table('memberships')
                ->join('roles', 'roles.id', '=', 'memberships.role_id')
                ->where('memberships.organization_id', $orgId)
                ->where('memberships.status', 'active')
                ->value('roles.key'),
            'بنیان‌گذار باید agency-admin سازمان شود',
        );

        // ─────────── ۳) مشتری ← پروژه ← سایت ───────────
        $this->post('/app/clients', ['name' => 'فروشگاه لیونا'])
            ->assertRedirect();

        $clientId = (int) DB::table('clients')->where('organization_id', $orgId)->value('id');

        $this->post('/app/projects', [
            'client_id' => $clientId,
            'name' => 'رشد ارگانیک سئو',
            'objective' => 'افزایش ترافیک ارگانیک',
        ])->assertRedirect();

        $projectId = (int) DB::table('projects')->where('organization_id', $orgId)->value('id');

        $this->post('/app/sites', [
            'project_id' => $projectId,
            'name' => 'لیونا',
            'canonical_url' => 'https://e.ir',
            'locale' => 'fa',
            'timezone' => 'Asia/Tehran',
            'business_importance' => 4,
        ])->assertRedirect();

        $siteId = (int) DB::table('sites')->where('organization_id', $orgId)->value('id');
        Assert::assertGreaterThan(0, $siteId, 'سایت باید ساخته شود');

        // ─────────── ۴) اتصال پلاگین وردپرس (Pairing) ───────────
        $tokenResponse = $this->post("/app/sites/{$siteId}/connector/pairing-token");
        $tokenResponse->assertRedirect();
        $pairingToken = (string) $tokenResponse->getSession()->get('pairingToken');
        Assert::assertSame(64, strlen($pairingToken), 'توکن جفت‌سازی باید ۶۴ کاراکتر باشد');

        $pair = $this->postJson('/connector/pair', [
            'site_id' => $siteId,
            'pairing_token' => $pairingToken,
            'platform_url' => 'https://wp.test',
            'plugin_version' => '1.2.0',
        ]);
        $pair->assertOk();
        Assert::assertNotEmpty($pair->json('secret'), 'پلاگین باید secret امضا دریافت کند');

        $this->assertDatabaseHas('site_connections', [
            'site_id' => $siteId,
            'status' => 'connected',
            'platform_url' => 'https://wp.test',
        ]);

        // ─────────── ۵) پیکربندی سرویس هوش مصنوعی (RBAC: agency-admin) ───────────
        $this->post('/app/settings/ai-provider', [
            'provider' => 'openai',
            'api_key' => 'sk-e2e-money-path',
            'model' => 'gpt-4o-mini',
        ])->assertRedirect();

        Assert::assertSame(1, DB::table('ai_provider_settings')->where('organization_id', $orgId)->count());

        // ─────────── ۶) زیرساخت اتوماسیون: پروفایل L3، سیاست سایت، گرمایش، GSC تازه ───────────
        $profileId = DB::table('automation_profiles')->insertGetId([
            'name' => 'E2E L3', 'slug' => 'e2e-l3-'.Str::random(5), 'kind' => 'custom', 'scope' => 'site',
            'automation_level' => 3, 'ai_policy' => 'bounded_auto', 'confidence_threshold' => 80,
            'high_risk_threshold' => 85, 'risk_tier_max' => 'R3',
            'enabled_content_types' => json_encode(['article'], JSON_UNESCAPED_UNICODE),
            'daily_command_limit' => 25, 'daily_mutation_limit' => 10,
            'rollback_hours' => 336, 'auto_rollback' => true, 'alert_level' => 'alert',
            'reviewer_policy' => 'one', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('site_automation_policies')->insert([
            'site_id' => $siteId, 'level' => 3,
            'rules' => json_encode(['max_risk_tier' => 'R3', 'allowed_command_types' => ['publish_new_article']], JSON_UNESCAPED_UNICODE),
            'active_profile_id' => $profileId,
            'auto_publish_scope' => 'article',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < 5; $i++) {
            DB::table('commands')->insert([
                'site_id' => $siteId, 'source_type' => 'test', 'type' => 'update_content',
                'content_type' => 'article', 'risk_tier' => 'R2', 'payload' => '{}',
                'idempotency_key' => (string) Str::uuid(), 'status' => 'executed',
                'decision_source' => 'manual', 'expires_at' => now()->addHour(), 'policy_version' => 1,
                'created_at' => now()->subDays($i + 1), 'updated_at' => now(),
            ]);
        }
        $gscAccountId = DB::table('gsc_accounts')->insertGetId([
            'organization_id' => $orgId, 'google_subject' => 'e2e:'.Str::random(6), 'email' => 'gsc@e2e.test',
            'token_ciphertext' => Crypt::encryptString('token'), 'token_expires_at' => now()->addDay(),
            'status' => 'connected', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $propId = DB::table('gsc_properties')->insertGetId([
            'site_id' => $siteId, 'gsc_account_id' => $gscAccountId, 'property_uri' => 'sc-domain:e.ir',
            'property_type' => 'site', 'status' => 'selected', 'selected_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('gsc_import_runs')->insert([
            'gsc_property_id' => $propId, 'status' => 'completed',
            'date_start' => now()->subDays(2)->toDateString(), 'date_end' => now()->toDateString(),
            'finished_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ─────────── ۷) تولید مقاله با هوش مصنوعی ───────────
        $profileUrlId = DB::table('url_profiles')->insertGetId([
            'site_id' => $siteId, 'public_id' => (string) Str::ulid(),
            'canonical_url' => 'https://e.ir/blog/seo-guide/',
            'content_type' => 'page', 'post_status' => 'publish',
            'metadata' => json_encode(['gsc' => ['clicks' => 30, 'impressions' => 1200, 'ctr' => 0.025, 'position' => 11]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post('/app/ai-drafts/article', [
            'url_profile_id' => $profileUrlId,
            'title' => 'آموزش سئو: راهنمای جامع بهینه‌سازی سایت',
        ])->assertRedirect(route('app.reviews.index'))->assertSessionHas('status');

        $generation = DB::table('ai_generations')->where('site_id', $siteId)->first();
        Assert::assertNotNull($generation, 'نسل AI باید ثبت شود');
        $version = DB::table('ai_generation_versions')->where('id', $generation->current_version_id)->first();
        Assert::assertNotNull($version, 'نسخهٔ خروجی باید ثبت شود');
        $output = json_decode($version->output, true);
        Assert::assertSame('ai', $output['source'], 'باید از provider واقعی (نه fallback) بیاید');

        $reviewId = (int) DB::table('review_items')
            ->where('subject_type', 'ai_generation')->where('subject_id', $generation->id)
            ->value('id');
        Assert::assertGreaterThan(0, $reviewId, 'پیش‌نویس باید وارد صف بازبینی انسانی شود');

        // ─────────── ۸) تأیید انسانی ← انتشار خودکار روی وردپرس ───────────
        $this->post("/app/reviews/{$reviewId}/decision", [
            'decision' => 'approved',
            'note' => 'تأیید برای انتشار',
        ])->assertRedirect();

        $command = DB::table('commands')
            ->where('site_id', $siteId)->where('type', 'publish_new_article')
            ->where('source_type', 'ai_generation')->where('source_id', $generation->id)
            ->first();

        Assert::assertNotNull($command, 'تأیید باید کامند publish_new_article بسازد');
        Assert::assertSame('executed', $command->status, 'با همهٔ گیت‌های باز، انتشار خودکار باید اجرا شود. decision='.print_r(json_decode((string) $command->confidence_factors, true), true));
        Assert::assertSame('policy', $command->decision_source);

        // فرمان امضاشده به وردپرس ارسال شده است
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'wp-json/vision-prime/v1/commands'));

        // ─────────── ۹) رد حسابرسی کامل ───────────
        foreach ([
            'auth.registered',
            'connector.pairing_token_created',
            'connector.paired',
            'ai.provider_setting_saved',
            'site.created',
            'ai.article_draft_generated',
            'review.decided',
            'article_draft.approved_pipeline',
        ] as $action) {
            Assert::assertTrue(
                DB::table('audit_logs')->where('action', $action)->exists(),
                "رد حسابرسی باید «{$action}» را داشته باشد",
            );
        }
    }

    /** محتوای مقالهٔ نمونه که گیت‌های کیفیت (طول، ساختار، FAQ، CTA، لینک داخلی) را پاس می‌کند. */
    private function articleHtml(): string
    {
        $paragraph = str_repeat('متن کامل درباره بهینه‌سازی موتورهای جستجو و رتبه‌بندی سایت شما در نتایج گوگل و سایر موتورهای جستجوگر معتبر و کسب و کار اینترنتی. ', 15);
        $link = '<a href="https://e.ir/guide">راهنمای سئو</a>';

        return '<p>فهرست مطالب: مقدمه، مراحل، سؤالات متداول، جمع‌بندی</p>'
            .'<h2>مقدمه</h2><p>آموزش سئو برای بهبود رتبه سایت شما در نتایج جستجو اهمیت زیادی دارد. '.$paragraph.' '.$link.'</p>'
            .'<h2>مراحل اجرای سئو</h2><ol><li>گام اول: تحلیل وضعیت فعلی</li><li>گام دوم: اجرای بهینه‌سازی</li><li>گام سوم: اندازه‌گیری نتیجه</li></ol><p>'.$paragraph.' '.$link.'</p>'
            .'<h2>سؤالات متداول</h2><p><strong>پرسش:</strong> آیا سئو مهم است؟ <strong>پاسخ:</strong> بله.</p>'
            .'<h2>جمع‌بندی</h2><p>'.$paragraph.' '.$link.' برای مشاوره همین حالا با تیم ما تماس بگیرید.</p>';
    }
}
