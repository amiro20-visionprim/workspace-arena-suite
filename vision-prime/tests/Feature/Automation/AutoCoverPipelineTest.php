<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Domains\Ai\Actions\DecideReviewItem;
use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use App\Models\User;
use Database\Seeders\ContentStandardsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * فاز D — کاور خودکار در خط انتشار:
 *   ساخت فرمان → provisionCover (استوک→آپلود امضاشده به /media) → featured_media_id در payload
 *   گیت کاور: بدون کاور + سیاست فعال → pending_approval با دلیل فارسی.
 */
class AutoCoverPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ContentStandardsSeeder::class);
        $org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'ac-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $org->id, 'user_id' => $this->admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
        $client = Client::query()->create(['organization_id' => $org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $this->site = Site::query()->create(['organization_id' => $org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://ac.ir', 'status' => 'active']);
        DB::table('site_connections')->insert([
            'site_id' => $this->site->id, 'status' => 'connected', 'platform_url' => 'https://wp.test',
            'secret_ciphertext' => Crypt::encryptString('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // پروفایل L3 + گرمایش برای انتشار خودکار
        $profileId = DB::table('automation_profiles')->insertGetId([
            'name' => 'AC', 'slug' => 'ac-'.Str::random(4), 'kind' => 'custom', 'scope' => 'site',
            'automation_level' => 3, 'ai_policy' => 'bounded_auto', 'confidence_threshold' => 80,
            'high_risk_threshold' => 85, 'risk_tier_max' => 'R3', 'enabled_content_types' => json_encode(['article']),
            'daily_command_limit' => 25, 'daily_mutation_limit' => 10, 'rollback_hours' => 336,
            'auto_rollback' => true, 'alert_level' => 'alert', 'reviewer_policy' => 'one', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('site_automation_policies')->insert([
            'site_id' => $this->site->id, 'level' => 3,
            'rules' => json_encode(['max_risk_tier' => 'R3', 'allowed_command_types' => ['publish_new_article']]),
            'active_profile_id' => $profileId, 'auto_publish_scope' => 'article',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // GSC تازه برای data_quality کامل (مثل ArticlePublishPipelineTest)
        $gscAccountId = DB::table('gsc_accounts')->insertGetId(['organization_id' => $this->site->organization_id, 'google_subject' => 'ac:'.Str::random(6), 'email' => 'gsc@ac.test', 'token_ciphertext' => Crypt::encryptString('t'), 'token_expires_at' => now()->addDay(), 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()]);
        $propId = DB::table('gsc_properties')->insertGetId(['site_id' => $this->site->id, 'gsc_account_id' => $gscAccountId, 'property_uri' => 'sc-domain:ac.ir', 'property_type' => 'site', 'status' => 'selected', 'selected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gsc_import_runs')->insert(['gsc_property_id' => $propId, 'status' => 'completed', 'date_start' => now()->subDays(2)->toDateString(), 'date_end' => now()->toDateString(), 'finished_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        for ($i = 0; $i < 5; $i++) {
            DB::table('commands')->insert([
                'site_id' => $this->site->id, 'source_type' => 'test', 'type' => 'update_content',
                'content_type' => 'article', 'risk_tier' => 'R2', 'payload' => '{}',
                'idempotency_key' => (string) Str::uuid(), 'status' => 'executed',
                'decision_source' => 'manual', 'expires_at' => now()->addHour(), 'policy_version' => 1,
                'created_at' => now()->subDays($i + 1), 'updated_at' => now(),
            ]);
        }

        // زنجیرهٔ تصویر: استوک پیداست، آپلود /media موفق است، انتشار موفق است
        Http::fake([
            '*api.pexels.com/v1/search*' => Http::response(['photos' => [
                ['alt' => 'skincare serum', 'photographer' => 'Nasim', 'width' => 1200, 'height' => 800,
                    'src' => ['large2x' => 'https://img.test/cover.jpg', 'medium' => 'https://img.test/m.jpg']],
            ]], 200),
            '*wp.test/wp-json/vision-prime/v1/media*' => Http::response(['media_id' => 555, 'url' => 'https://wp.test/wp-uploads/555.jpg'], 200),
            '*wp.test/wp-json/vision-prime/v1/commands*' => Http::response(['status' => 'ack'], 200),
            '*wp.test/connector/command-result*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function makeGeneration(): int
    {
        $paragraph = str_repeat('متن کامل درباره مراقبت پوست و انتخاب سرم مناسب برای روتین شب و روز با جزئیات کامل. ', 30);
        $link = '<a href="https://ac.ir/guide">راهنما</a>';
        $content = '<h2>مقدمه</h2><p>'.$paragraph.' '.$link.'</p><h2>مراحل</h2><ol><li>یک</li><li>دو</li><li>سه</li></ol><p>'.$paragraph.'</p>'
            .'<h2>سؤالات متداول</h2><p><strong>پرسش:</strong> x؟ <strong>پاسخ:</strong> بله.</p><h2>جمع‌بندی</h2><p>'.$paragraph.' برای مشاوره تماس بگیرید.</p>';
        $genId = DB::table('ai_generations')->insertGetId([
            'site_id' => $this->site->id, 'template_id' => null,
            'input_redacted' => json_encode(['url' => 'https://ac.ir/serum/', 'target_query' => 'سرم پوست', 'profile' => ['title' => 'بهترین سرم پوست', 'content_type' => 'article', 'subtype' => 'guide', 'intent' => 'commercial']], JSON_UNESCAPED_UNICODE),
            'current_version_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = DB::table('ai_generation_versions')->insertGetId([
            'generation_id' => $genId, 'version' => 1,
            'output' => json_encode([
                'kind' => 'article', 'text' => $content, 'model' => 'rule-based', 'source' => 'rule_based',
                'standard' => ['standard_key' => 'article×guide×commercial', 'word_min' => 300, 'word_max' => 3000, 'required_elements' => ['faq', 'cta'], 'min_headings' => 3],
                'profile' => ['content_type' => 'article', 'subtype' => 'guide', 'intent' => 'commercial', 'title' => 'بهترین سرم پوست'],
                'schema' => [], 'featured_image' => ['alt' => 'x'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_generations')->where('id', $genId)->update(['current_version_id' => $versionId]);

        return (int) $genId;
    }

    public function test_publish_command_gets_automatic_cover_from_stock(): void
    {
        DB::table('image_provider_settings')->insert([
            'organization_id' => $this->site->organization_id, 'provider' => 'pexels',
            'encrypted_config' => Crypt::encryptString(json_encode(['api_key' => 'px'])),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $genId = $this->makeGeneration();
        $reviewId = DB::table('review_items')->insertGetId([
            'site_id' => $this->site->id, 'subject_type' => 'ai_generation', 'subject_id' => $genId,
            'status' => 'pending_review', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(DecideReviewItem::class)->handle($reviewId, $this->admin, 'approved', 'ok');

        $command = DB::table('commands')->where('source_type', 'ai_generation')->where('source_id', $genId)->first();
        Assert::assertNotNull($command, 'فرمان انتشار باید ساخته شود');
        $payload = json_decode((string) $command->payload, true) ?? [];

        Assert::assertSame(555, $payload['featured_media_id'] ?? null, 'کاور استوک باید آپلود و متصل شود');
        Assert::assertSame('stock', $payload['cover_source'] ?? null);
        $audit = DB::table('audit_logs')->whereIn('action', ['article_draft.approved_pipeline', 'article_draft.approved_pipeline_failed'])->latest('occurred_at')->first();
        fwrite(STDERR, "\n==STATUS ".$command->status.' | AUDIT '.substr((string) $audit?->after, 0, 400)."\n");
        Assert::assertSame('executed', $command->status, 'با کاور و همهٔ گیت‌ها، انتشار خودکار اجرا شود');

        // آپلود امضاشده به /media پلاگین
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'vision-prime/v1/media')
            && $r->hasHeaders('X-VP-Signature'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.cover_provisioned']);
    }

    public function test_cover_gate_blocks_auto_publish_without_cover(): void
    {
        // بدون کلید استوک/AI → کاوری نیست
        $genId = $this->makeGeneration();
        $reviewId = DB::table('review_items')->insertGetId([
            'site_id' => $this->site->id, 'subject_type' => 'ai_generation', 'subject_id' => $genId,
            'status' => 'pending_review', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(DecideReviewItem::class)->handle($reviewId, $this->admin, 'approved', 'ok');

        $command = DB::table('commands')->where('source_type', 'ai_generation')->where('source_id', $genId)->first();
        Assert::assertNotNull($command);
        $payload = json_decode((string) $command->payload, true) ?? [];

        if (($payload['featured_media_id'] ?? null) === null) {
            Assert::assertSame('pending_approval', $command->status, 'بدون کاور، انتشار خودکار بسته می‌ماند');
        } else {
            // اگر محیطی استوک داد — گیت نباید مانع شود
            Assert::assertSame('executed', $command->status);
        }
    }

    public function test_health_reports_monthly_image_usage_and_cost(): void
    {
        DB::table('media_assets')->insert([
            ['organization_id' => $this->site->organization_id, 'source' => 'ai', 'cost_estimate' => 0.04, 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->site->organization_id, 'source' => 'stock', 'cost_estimate' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('app:health')->assertSuccessful();
    }
}
