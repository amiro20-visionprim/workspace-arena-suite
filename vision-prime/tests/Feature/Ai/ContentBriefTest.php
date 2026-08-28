<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * استودیوی محتوا v2 — فاز B:
 *   بریف محتوا از GSC + استاندارد؛ دسته/برچسب امضاشده از پلاگین v1.4.
 */
class ContentBriefTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'br-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $this->org->id, 'user_id' => $this->admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
        $client = Client::query()->create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $this->org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $this->site = Site::query()->create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://b.ir', 'status' => 'active']);
    }

    public function test_brief_is_built_from_gsc_and_standard(): void
    {
        DB::table('keyword_insights')->insert([
            'site_id' => $this->site->id, 'query_normalized' => 'سرم پوست',
            'latest_metrics' => json_encode(['impressions' => 900]), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->postJson('/api/content/brief', ['site_id' => $this->site->id, 'title' => 'بهترین سرم پوست ۱۴۰۵'])
            ->assertOk();

        $res->assertJsonPath('success', true);
        $brief = $res->json('brief');
        Assert::assertSame('سرم پوست', $brief['target_query']);
        Assert::assertNotEmpty($brief['word_range']);
        Assert::assertSame('سرم پوست', $brief['gsc_queries'][0]['query'] ?? '');
        Assert::assertNotSame('', (string) $brief['subtype']);
    }

    public function test_brief_rejects_foreign_site(): void
    {
        $other = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'X', 'slug' => 'x-'.Str::random(4), 'status' => 'active']);

        $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->postJson('/api/content/brief', ['site_id' => $this->site->id, 'title' => 't'])
            ->assertOk();

        // سایت سازمان دیگر → 404
        $foreignSite = Site::query()->create([
            'organization_id' => $other->id, 'project_id' => Project::query()->create([
                'organization_id' => $other->id, 'client_id' => Client::query()->create([
                    'organization_id' => $other->id, 'public_id' => (string) Str::ulid(), 'name' => 'FC', 'status' => 'active'])->id,
                'public_id' => (string) Str::ulid(), 'name' => 'FP', 'status' => 'active'])->id,
            'public_id' => (string) Str::ulid(), 'name' => 'FS', 'canonical_url' => 'https://x.ir', 'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->postJson('/api/content/brief', ['site_id' => $foreignSite->id, 'title' => 't'])
            ->assertStatus(404);
    }

    public function test_taxonomies_come_from_signed_plugin_and_cache(): void
    {
        DB::table('site_connections')->insert([
            'site_id' => $this->site->id, 'status' => 'connected', 'platform_url' => 'https://wp.test',
            'secret_ciphertext' => Crypt::encryptString('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['*wp.test/wp-json/vision-prime/v1/taxonomies*' => Http::response([
            'categories' => [['id' => 5, 'name' => 'سئو', 'slug' => 'seo', 'count' => 4]],
            'tags' => [['id' => 11, 'name' => 'راهنما', 'slug' => 'guide', 'count' => 7]],
        ], 200)]);

        $res = $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->getJson("/app/sites/{$this->site->id}/taxonomies")
            ->assertOk();

        $res->assertJsonPath('success', true)
            ->assertJsonPath('connected', true)
            ->assertJsonPath('categories.0.name', 'سئو')
            ->assertJsonPath('tags.0.count', 7);

        // درخواست امضاشده به پلاگین
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'vision-prime/v1/taxonomies')
            && $r->hasHeaders('X-VP-Signature'));

        // کش: بار دوم بدون تماس شبکهٔ جدید
        $this->actingAs($this->admin)->withSession(['current_organization_id' => $this->org->id])
            ->getJson("/app/sites/{$this->site->id}/taxonomies")->assertOk();
        Http::assertSentCount(1);
    }

    public function test_taxonomies_without_connection_returns_guidance(): void
    {
        $res = $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->getJson("/app/sites/{$this->site->id}/taxonomies")
            ->assertOk();

        $res->assertJsonPath('success', false)->assertJsonPath('connected', false);
    }
}
