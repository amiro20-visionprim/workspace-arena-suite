<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domains\Automation\Actions\PostPublishVerifier;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostPublishVerifierTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $o = Organization::create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'o', 'status' => 'active']);
        $c = Client::create(['organization_id' => $o->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $p = Project::create(['organization_id' => $o->id, 'client_id' => $c->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $this->site = Site::create(['organization_id' => $o->id, 'project_id' => $p->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://e.ir', 'status' => 'active']);
    }

    private function createCommand(string $type, string $url, string $value = ''): int
    {
        $payload = match ($type) {
            'update_meta_title' => ['url' => $url, 'title' => $value],
            'update_meta_description' => ['url' => $url, 'description' => $value],
            default => ['url' => $url, 'content' => $value],
        };

        return (int) DB::table('commands')->insertGetId([
            'site_id' => $this->site->id,
            'source_type' => 'test',
            'type' => $type,
            'content_type' => str_starts_with($type, 'update_meta_') ? 'meta' : 'article',
            'risk_tier' => 'R1',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'executed',
            'confidence_score' => 85,
            'expires_at' => now()->addDays(7),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_missing_command_returns_not_found(): void
    {
        $result = app(PostPublishVerifier::class)->verify(99999);
        $this->assertFalse($result['verified']);
        $this->assertStringContainsString('یافت نشد', $result['reason']);
    }

    public function test_command_without_url_returns_error(): void
    {
        $id = DB::table('commands')->insertGetId([
            'site_id' => $this->site->id,
            'source_type' => 'test',
            'type' => 'update_meta_title',
            'content_type' => 'meta',
            'risk_tier' => 'R1',
            'payload' => json_encode(['title' => 'test']),
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'executed',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PostPublishVerifier::class)->verify($id);
        $this->assertFalse($result['verified']);
        $this->assertStringContainsString('URL', $result['reason']);
    }

    public function test_unknown_type_passes_without_check(): void
    {
        // نوع ناشناخته که در match هیچ شاخه‌ای نمی‌خوره
        $id = (int) DB::table('commands')->insertGetId([
            'site_id' => $this->site->id,
            'source_type' => 'test',
            'type' => 'unknown_type_xyz',
            'content_type' => 'article',
            'risk_tier' => 'R1',
            'payload' => json_encode(['url' => 'https://example.ir/test']),
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'executed',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $result = app(PostPublishVerifier::class)->verify($id);
        // نوع تأیید ناپذیر — بدون بررسی قبول می‌شود
        $this->assertTrue($result['verified']);
    }
}