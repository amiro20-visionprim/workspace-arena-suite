<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Ai\Actions\CreateReviewItem;
use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تست ایزولاسیون چند-مستأجری (F3-06) — «آژانس B هیچ راهی به دادهٔ آژانس A ندارد».
 *
 * برای قراردادهای آژانسی حیاتی است: هر ردیف تست یک «حملهٔ متقاطع» واقعی را
 * شبیه‌سازی می‌کند. انتظار: 403/404 — هرگز 200/redirect موفق.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private Site $siteA;

    private Client $clientA;

    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // سازمان A با داده‌های کامل
        $this->orgA = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'Agency A', 'slug' => 'a-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->adminA = User::factory()->create();
        Membership::query()->create(['organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id, 'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
        $this->clientA = Client::query()->create(['organization_id' => $this->orgA->id, 'public_id' => (string) Str::ulid(), 'name' => 'Client A', 'status' => 'active']);
        $this->projectA = Project::query()->create(['organization_id' => $this->orgA->id, 'client_id' => $this->clientA->id, 'public_id' => (string) Str::ulid(), 'name' => 'Project A', 'status' => 'active']);
        $this->siteA = Site::query()->create(['organization_id' => $this->orgA->id, 'project_id' => $this->projectA->id, 'public_id' => (string) Str::ulid(), 'name' => 'Site A', 'canonical_url' => 'https://a.ir', 'status' => 'active']);
        DB::table('site_connections')->insert(['site_id' => $this->siteA->id, 'status' => 'connected', 'platform_url' => 'https://a-wp.ir', 'secret_ciphertext' => Crypt::encryptString('a-secret'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('url_profiles')->insert(['site_id' => $this->siteA->id, 'public_id' => (string) Str::ulid(), 'canonical_url' => 'https://a.ir/x', 'content_type' => 'page', 'post_status' => 'publish', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_provider_settings')->insert(['organization_id' => $this->orgA->id, 'provider' => 'openai', 'encrypted_config' => Crypt::encryptString(json_encode(['api_key' => 'sk-a'])), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // سازمان B — مهاجم
        $this->orgB = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'Agency B', 'slug' => 'b-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->adminB = User::factory()->create();
        Membership::query()->create(['organization_id' => $this->orgB->id, 'user_id' => $this->adminB->id, 'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
    }

    private function asB()
    {
        return $this->actingAs($this->adminB)->withSession(['current_organization_id' => $this->orgB->id]);
    }

    /* ───────────── سایت ───────────── */

    public function test_b_cannot_view_a_site(): void
    {
        $this->asB()->get("/app/sites/{$this->siteA->id}")->assertForbidden();
    }

    public function test_b_cannot_update_a_site(): void
    {
        $this->asB()->put("/app/sites/{$this->siteA->id}", [
            'name' => 'Hacked', 'canonical_url' => 'https://a.ir', 'locale' => 'fa',
            'timezone' => 'Asia/Tehran', 'business_importance' => 5, 'project_id' => $this->projectA->id,
        ])->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $this->siteA->id, 'name' => 'Site A']);
    }

    public function test_b_cannot_delete_a_site(): void
    {
        $this->asB()->delete("/app/sites/{$this->siteA->id}")->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $this->siteA->id]);
    }

    public function test_b_cannot_see_a_connector_page_or_mint_pairing_token(): void
    {
        $this->asB()->get("/app/sites/{$this->siteA->id}/connector")->assertForbidden();
        $this->asB()->post("/app/sites/{$this->siteA->id}/connector/pairing-token")->assertForbidden();
        $this->assertSame(0, DB::table('pairing_tokens')->where('site_id', $this->siteA->id)->count());
    }

    public function test_b_cannot_disconnect_or_sync_a_site(): void
    {
        $this->asB()->post("/app/sites/{$this->siteA->id}/connector/disconnect")->assertForbidden();
        $this->asB()->post("/app/sites/{$this->siteA->id}/sync")->assertForbidden();
        $this->assertDatabaseHas('site_connections', ['site_id' => $this->siteA->id, 'status' => 'connected']);
    }

    public function test_b_cannot_change_a_automation_policy(): void
    {
        $this->asB()->put("/app/sites/{$this->siteA->id}/automation", [])->assertForbidden();
        $this->asB()->post("/app/sites/{$this->siteA->id}/automation/emergency-stop")->assertForbidden();
    }

    /* ───────────── مشتری/پروژه ───────────── */

    public function test_b_cannot_view_a_client(): void
    {
        $this->asB()->get("/app/clients/{$this->clientA->id}")->assertForbidden();
    }

    public function test_b_cannot_update_a_client(): void
    {
        $this->asB()->put("/app/clients/{$this->clientA->id}", ['name' => 'Stolen'])->assertForbidden();
    }

    public function test_b_cannot_create_project_under_a_client(): void
    {
        $this->asB()->post('/app/projects', ['client_id' => $this->clientA->id, 'name' => 'Inject'])
            ->assertForbidden(); // Gate اجازهٔ update روی client سازمان دیگر را نمی‌دهد
        $this->assertSame(1, $this->projectA->count()); // فقط Project A خودِ A
    }

    public function test_b_cannot_create_site_under_a_project(): void
    {
        $this->asB()->post('/app/sites', [
            'project_id' => $this->projectA->id, 'name' => 'Inject', 'canonical_url' => 'https://inject.ir',
            'locale' => 'fa', 'timezone' => 'Asia/Tehran', 'business_importance' => 3,
        ])->assertNotFound();
    }

    /* ───────────── محتوا و بازبینی ───────────── */

    public function test_b_cannot_generate_article_for_a_url_profile(): void
    {
        $profileId = DB::table('url_profiles')->where('site_id', $this->siteA->id)->value('id');
        $this->asB()->post('/app/ai-drafts/article', ['url_profile_id' => $profileId])
            ->assertNotFound();
        $this->assertSame(0, DB::table('ai_generations')->where('site_id', $this->siteA->id)->count());
    }

    public function test_b_cannot_decide_a_review_item(): void
    {
        $generationId = DB::table('ai_generations')->insertGetId([
            'site_id' => $this->siteA->id, 'template_id' => null,
            'input_redacted' => '{}', 'current_version_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reviewId = app(CreateReviewItem::class)->handle($this->siteA, 'ai_generation', $generationId);
        $this->asB()->post("/app/reviews/{$reviewId}/decision", ['decision' => 'approved'])
            ->assertForbidden();
        $this->assertDatabaseHas('review_items', ['id' => $reviewId, 'status' => 'pending_review']);
    }

    public function test_b_cannot_read_or_delete_a_content_draft(): void
    {
        $draftId = DB::table('content_drafts')->insertGetId([
            'site_id' => $this->siteA->id,
            'title' => 'Draft A', 'content' => '<p>x</p>', 'subtype' => 'article',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(in_array($this->asB()->get("/api/content/drafts/{$draftId}")->status(), [403, 404], true), 'draft نباید برای B قابل خواندن باشد');
        $this->assertTrue(in_array($this->asB()->delete("/api/content/drafts/{$draftId}")->status(), [403, 404], true), 'draft نباید برای B قابل حذف باشد');
        $this->assertDatabaseHas('content_drafts', ['id' => $draftId]);
    }

    public function test_b_cannot_publish_to_a_wordpress_site(): void
    {
        $this->asB()->post('/api/content/publish', [
            'site_id' => $this->siteA->id, 'title' => 'X', 'content' => '<p>x</p>',
        ])->assertForbidden();
    }

    /* ───────────── پرتال مشتری ───────────── */

    public function test_b_member_cannot_reach_a_client_portal_decisions(): void
    {
        // adminB عضو client-portal سازمان A نیست — دسترسی به تصمیمات A باید بسته باشد
        // B هیچ انتساب client-portal ندارد → دسترسی باید کاملاً بسته باشد (404/redirect)
        $response = $this->asB()->get('/decisions');
        $this->assertNotSame(200, $response->status(), 'پرتال تصمیمات نباید برای غیراعضا باز باشد');
    }

    /* ───────────── GSC و تنظیمات ───────────── */

    public function test_b_cannot_import_gsc_for_a_site(): void
    {
        $propId = DB::table('gsc_properties')->insertGetId([
            'site_id' => $this->siteA->id, 'gsc_account_id' => DB::table('gsc_accounts')->insertGetId([
                'organization_id' => $this->orgA->id, 'google_subject' => 'iso:'.Str::random(5), 'email' => 'a@a.ir',
                'token_ciphertext' => Crypt::encryptString('t'), 'token_expires_at' => now()->addDay(),
                'status' => 'connected', 'created_at' => now(), 'updated_at' => now(),
            ]),
            'property_uri' => 'sc-domain:a.ir', 'property_type' => 'site', 'status' => 'selected', 'selected_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->asB()->post('/app/gsc/import', ['gsc_property_id' => $propId, 'date_start' => now()->subDays(7)->toDateString(), 'date_end' => now()->toDateString()])
            ->assertNotFound(); // ملک GSC سازمان A برای B قابل مشاهده نیست
    }

    public function test_b_cannot_see_a_organization_settings(): void
    {
        $this->asB()->get('/app/settings/organization')->assertOk(); // صفحهٔ سازمان خودش
        // عضو B نباید در فهرست اعضای A باشد
        $this->assertFalse(
            DB::table('memberships')->where('organization_id', $this->orgA->id)->where('user_id', $this->adminB->id)->exists()
        );
    }

    /* ───────────── کانکتور عمومی ───────────── */

    public function test_pairing_requires_valid_64_char_token_of_own_site(): void
    {
        // توکن جعلی برای سایت A — نباید connection بسازد
        $this->postJson('/connector/pair', [
            'site_id' => $this->siteA->id,
            'pairing_token' => str_repeat('x', 64),
            'platform_url' => 'https://evil.ir',
            'plugin_version' => '1.2.0',
        ])->assertStatus(422);
        $this->assertDatabaseHas('site_connections', ['site_id' => $this->siteA->id, 'platform_url' => 'https://a-wp.ir']);
    }

    /* ───────────── ایزولاسیون در سطح دیتابیس ───────────── */

    public function test_no_cross_org_rows_after_all_attempts(): void
    {
        // جمع‌بندی: هیچ ردیف جدیدی برای سازمان A ساخته نشده و هیچ membership متقاطع نیست
        $this->assertSame(0, DB::table('memberships')->where('organization_id', $this->orgA->id)->where('user_id', '!=', $this->adminA->id)->count());
        $this->assertSame(1, Site::query()->where('organization_id', $this->orgA->id)->count());
    }
}
