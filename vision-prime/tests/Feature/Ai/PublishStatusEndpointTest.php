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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * R1-3 «انتشار صادق»: اندپوینت publish-status باید وضعیت واقعی فرمان و
 * post_url را از callback پلاگین (command_execution_logs) برگرداند.
 */
class PublishStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'ps-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $this->org->id, 'user_id' => $this->admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
        $client = Client::query()->create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $this->org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $this->site = Site::query()->create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://ps.ir', 'status' => 'active']);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['current_organization_id' => $this->org->id]);
    }

    public function test_returns_executed_status_with_real_post_url_from_callback(): void
    {
        $commandId = DB::table('commands')->insertGetId([
            'site_id' => $this->site->id, 'source_type' => 'content_draft', 'source_id' => 1,
            'type' => 'publish_new_article', 'content_type' => 'article', 'risk_tier' => 'R3',
            'payload' => '{}', 'idempotency_key' => (string) Str::uuid(), 'status' => 'executed',
            'decision_source' => 'manual', 'expires_at' => now()->addDay(), 'policy_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('command_execution_logs')->insert([
            'command_id' => $commandId, 'status' => 'executed',
            'response_redacted' => json_encode(['callback' => true, 'result' => ['post_id' => 4242, 'url' => 'https://wp.test/?p=4242']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asAdmin()->getJson("/api/content/publish-status?command_id={$commandId}")
            ->assertOk()
            ->assertJsonPath('status', 'executed')
            ->assertJsonPath('post_id', 4242)
            ->assertJsonPath('post_url', 'https://wp.test/?p=4242');

        Assert::assertTrue(true);
    }

    public function test_returns_failed_with_plugin_error(): void
    {
        $commandId = DB::table('commands')->insertGetId([
            'site_id' => $this->site->id, 'source_type' => 'content_draft', 'source_id' => 1,
            'type' => 'publish_new_article', 'content_type' => 'article', 'risk_tier' => 'R3',
            'payload' => '{}', 'idempotency_key' => (string) Str::uuid(), 'status' => 'failed',
            'decision_source' => 'manual', 'expires_at' => now()->addDay(), 'policy_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('command_execution_logs')->insert([
            'command_id' => $commandId, 'status' => 'failed',
            'response_redacted' => json_encode(['callback' => true, 'result' => null, 'error' => 'Command payload has no title or content.'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asAdmin()->getJson("/api/content/publish-status?command_id={$commandId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'Command payload has no title or content.');
    }

    public function test_foreign_organization_command_is_not_visible(): void
    {
        $other = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'X', 'slug' => 'x-'.Str::random(4), 'status' => 'active']);
        $client = Client::query()->create(['organization_id' => $other->id, 'public_id' => (string) Str::ulid(), 'name' => 'XC', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $other->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'XP', 'status' => 'active']);
        $foreignSite = Site::query()->create(['organization_id' => $other->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'XS', 'canonical_url' => 'https://x.ir', 'status' => 'active']);
        $commandId = DB::table('commands')->insertGetId([
            'site_id' => $foreignSite->id, 'source_type' => 'test', 'source_id' => null,
            'type' => 'publish_new_article', 'content_type' => 'article', 'risk_tier' => 'R3',
            'payload' => '{}', 'idempotency_key' => (string) Str::uuid(), 'status' => 'executed',
            'decision_source' => 'manual', 'expires_at' => now()->addDay(), 'policy_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asAdmin()->getJson("/api/content/publish-status?command_id={$commandId}")
            ->assertStatus(404);
    }
}
