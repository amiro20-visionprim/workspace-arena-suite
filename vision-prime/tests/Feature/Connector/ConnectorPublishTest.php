<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Domains\Content\Models\ContentDraft;
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
 * انتشار دستی پیش‌نویس از طریق کانکتور امضاشده (مسیر واحد v1.3) —
 * بدون هیچ REST URL و Application Password.
 */
class ConnectorPublishTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'cp-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $org->id, 'user_id' => $this->admin->id, 'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
        $client = Client::query()->create(['organization_id' => $org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $this->site = Site::query()->create(['organization_id' => $org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://c.ir', 'status' => 'active']);
    }

    private function draft(): ContentDraft
    {
        return ContentDraft::query()->create([
            'site_id' => $this->site->id, 'title' => 'مقالهٔ تست', 'content' => '<p>محتوا</p>',
            'subtype' => 'article', 'status' => 'draft',
        ]);
    }

    public function test_publishes_draft_through_signed_connector_without_any_wp_password(): void
    {
        DB::table('site_connections')->insert([
            'site_id' => $this->site->id, 'status' => 'connected', 'platform_url' => 'https://wp.test',
            'secret_ciphertext' => Crypt::encryptString('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['*/wp-json/vision-prime/v1/commands' => Http::response(['status' => 'ack'], 200)]);

        $draft = $this->draft();
        $response = $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->site->organization_id])
            ->postJson('/api/content/publish-stored', ['draft_id' => $draft->id, 'status' => 'draft']);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('via', 'connector');

        $command = DB::table('commands')->where('source_type', 'content_draft')->where('source_id', $draft->id)->first();
        Assert::assertNotNull($command, 'فرمان publish_new_article باید ثبت شود');
        $outer = json_decode((string) $command->payload, true);
        $payload = is_array($outer['payload'] ?? null) ? $outer['payload'] : json_decode((string) ($outer['payload'] ?? '{}'), true);
        Assert::assertSame('draft', $payload['status'], 'وضعیت پیش‌نویس باید در فرمان برود');

        $draft->refresh();
        Assert::assertSame('draft', $draft->status);
        Assert::assertNotNull($draft->audit_log['connector_command_id'] ?? null);

        // ارسالِ امضاشده به پلاگین (هدرهای HMAC)
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'vision-prime/v1/commands')
            && $request->hasHeaders('X-VP-Signature'));
    }

    public function test_without_connector_returns_needs_setup_not_wp_credentials_error(): void
    {
        $draft = $this->draft();
        $response = $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->site->organization_id])
            ->postJson('/api/content/publish-stored', ['draft_id' => $draft->id]);

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('needs_setup', true);
        Assert::assertStringContainsString('app/sites/'.$this->site->id.'/connector', (string) $response->json('setup_url'));
        $this->assertSame(0, DB::table('commands')->where('site_id', $this->site->id)->count());
    }

    public function test_connector_health_check_endpoint_updates_connection(): void
    {
        DB::table('site_connections')->insert([
            'site_id' => $this->site->id, 'status' => 'connected', 'platform_url' => 'https://wp.test',
            'secret_ciphertext' => Crypt::encryptString('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['*/wp-json/vision-prime/v1/health' => Http::response(['plugin_version' => '1.3.1', 'wordpress_version' => '6.5'], 200)]);

        $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->site->organization_id])
            ->postJson("/app/sites/{$this->site->id}/connector/check")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('health.plugin_version', '1.3.1');

        $this->assertDatabaseHas('site_connections', ['site_id' => $this->site->id, 'status' => 'connected']);
    }
}
