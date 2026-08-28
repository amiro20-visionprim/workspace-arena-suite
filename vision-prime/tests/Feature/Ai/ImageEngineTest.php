<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domains\Ai\Services\ImageGateway;
use App\Domains\Content\Models\ContentDraft;
use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use App\Models\User;
use Database\Seeders\PlatformBillingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * موتور تصویر (فاز A — استودیو v2):
 *   تنظیم و تست سرویس‌ها (با مجوز)، جستجوی استوک با ترجمهٔ هوشمند،
 *   تولید AI با سهمیهٔ پلن، attach و پیشنهاد بدون شبکه.
 */
class ImageEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'img-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $this->org->id, 'user_id' => $this->admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['current_organization_id' => $this->org->id]);
    }

    public function test_admin_can_save_and_test_pexels_key(): void
    {
        Http::fake(['*api.pexels.com*' => Http::response(['photos' => [['id' => 1]]], 200)]);

        $this->asAdmin()->postJson('/api/content/image-provider', [
            'provider' => 'pexels', 'api_key' => 'px-key-123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('image_provider_settings', ['organization_id' => $this->org->id, 'provider' => 'pexels', 'status' => 'active']);
        // کلید رمزنگاری شده است
        $row = DB::table('image_provider_settings')->where('organization_id', $this->org->id)->first();
        Assert::assertStringNotContainsString('px-key-123', (string) $row->encrypted_config);

        $this->asAdmin()->postJson('/api/content/image-provider/test', [
            'provider' => 'pexels', 'api_key' => 'px-key-123',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_stock_search_translates_persian_query_and_returns_results(): void
    {
        Http::fake([
            '*api.pexels.com/v1/search*' => Http::response(['photos' => [
                ['alt' => 'serum bottle', 'photographer' => 'Nasim', 'width' => 1200, 'height' => 800,
                    'src' => ['large2x' => 'https://img.test/s.jpg', 'medium' => 'https://img.test/m.jpg']],
            ]], 200),
        ]);
        DB::table('image_provider_settings')->insert([
            'organization_id' => $this->org->id, 'provider' => 'pexels',
            'encrypted_config' => Crypt::encryptString(json_encode(['api_key' => 'px'])),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->asAdmin()->getJson('/api/content/images/search?q='.'سرم پوست')->assertOk();

        $res->assertJsonPath('success', true);
        Assert::assertSame('https://img.test/s.jpg', $res->json('results.0.url'));
        Assert::assertStringContainsString('Nasim', (string) $res->json('results.0.credit'));

        // درخواست به Pexels با کلیدواژهٔ انگلیسیِ ترجمه‌شده (RuleBased fallback متن انگلیسی تولید می‌کند یا همان کلیدواژه)
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'api.pexels.com'));
    }

    public function test_generate_requires_quota_and_creates_asset(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => Http::response([
            'data' => [['b64_json' => base64_encode(str_repeat('x', 128))]],
        ], 200)]);

        DB::table('image_provider_settings')->insert([
            'organization_id' => $this->org->id, 'provider' => 'openai-image',
            'encrypted_config' => Crypt::encryptString(json_encode(['api_key' => 'sk-img'])),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->asAdmin()->postJson('/api/content/images/generate', [
            'prompt' => 'کاور مقاله سئو با تم رشد', 'size' => '1536x1024', 'alt' => 'کاور سئو',
        ])->assertOk();

        $res->assertJsonPath('success', true);
        Assert::assertGreaterThan(0, (int) $res->json('asset_id'));
        $this->assertDatabaseHas('media_assets', ['organization_id' => $this->org->id, 'source' => 'ai', 'alt' => 'کاور سئو']);
    }

    public function test_generate_blocked_when_plan_quota_exhausted(): void
    {
        $this->seed(PlatformBillingSeeder::class);
        DB::table('subscriptions')->insert([
            'organization_id' => $this->org->id,
            'plan_id' => DB::table('plans')->where('key', 'starter')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'current_period_end' => now()->addMonth(),
        ]);
        // پر کردن سهمیه: starter = ۱۰ تصویر
        for ($i = 0; $i < 10; $i++) {
            DB::table('media_assets')->insert(['organization_id' => $this->org->id, 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->asAdmin()->postJson('/api/content/images/generate', ['prompt' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_limit');
    }

    public function test_attach_stock_asset_to_draft(): void
    {
        $client = Client::query()->create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $this->org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $site = Site::query()->create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://i.ir', 'status' => 'active']);
        $draft = ContentDraft::query()->create(['site_id' => $site->id, 'title' => 'T', 'content' => '<p>x</p>', 'subtype' => 'article', 'status' => 'draft']);

        $assetId = app(ImageGateway::class)->registerStockAsset($this->org, [
            'url' => 'https://img.test/a.jpg', 'alt' => 'تصویر شاخص', 'credit' => 'P / Pexels', 'provider' => 'pexels',
        ]);

        $this->asAdmin()->postJson('/api/content/images/attach', [
            'asset_id' => $assetId, 'draft_id' => $draft->id, 'slot' => 'cover',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('media_assets', ['id' => $assetId, 'draft_id' => $draft->id, 'slot' => 'cover', 'site_id' => $site->id]);
    }

    public function test_suggestions_always_available_without_network(): void
    {
        $this->asAdmin()->getJson('/api/content/images/suggest?title='.'راهنمای سئو&section=مقدمه')
            ->assertOk()
            ->assertJsonPath('success', true);
        Assert::assertNotEmpty($this->asAdmin()->getJson('/api/content/images/suggest?title=x')->json('suggestions'));
    }

    public function test_member_without_permission_cannot_save_keys(): void
    {
        $viewer = User::factory()->create();
        Membership::query()->create(['organization_id' => $this->org->id, 'user_id' => $viewer->id,
            'role_id' => Role::query()->where('key', 'client-viewer')->valueOrFail('id'), 'status' => 'active']);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id])
            ->postJson('/api/content/image-provider', ['provider' => 'pexels', 'api_key' => 'k'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
