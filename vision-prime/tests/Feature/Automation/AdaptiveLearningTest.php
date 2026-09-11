<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Domains\Automation\Actions\ConvertRecommendationToCommand;
use App\Domains\Automation\Jobs\LearningLoop;
use App\Domains\Automation\Services\AdaptiveLearning;
use App\Domains\Organization\Models\Organization;
use App\Domains\Seo\Actions\CreateRiskRecommendations;
use App\Domains\Seo\Models\Recommendation;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdaptiveLearningTest extends TestCase
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

    /** @param  string[]  $statuses  ترتیب زمانی (قدیمی → جدید) */
    private function seedCommands(array $statuses, string $type = 'update_meta_title'): void
    {
        foreach ($statuses as $i => $status) {
            \DB::table('commands')->insert([
                'site_id' => $this->site->id, 'source_type' => 'test', 'type' => $type, 'risk_tier' => 'R1',
                'payload' => json_encode([]), 'idempotency_key' => (string) Str::uuid(), 'status' => $status,
                'confidence_score' => 85, 'decision_source' => 'policy',
                'expires_at' => now()->addHour(), 'created_at' => now()->subDays(count($statuses) - $i), 'updated_at' => now(),
            ]);
        }
    }

    public function test_three_consecutive_failures_block_the_type(): void
    {
        $this->seedCommands(['executed', 'rolled_back', 'rolled_back', 'rolled_back']);

        (new LearningLoop(siteId: $this->site->id))->handle();

        $this->assertDatabaseHas('automation_learning_history', [
            'site_id' => $this->site->id, 'command_type' => 'update_meta_title',
            'total' => 4, 'successful' => 1, 'consecutive_failures' => 3, 'blocked' => true,
        ]);
        $this->assertTrue(app(AdaptiveLearning::class)->isBlocked($this->site->id, 'update_meta_title'));
        $this->assertNotNull(app(AdaptiveLearning::class)->blockReason($this->site->id, 'update_meta_title'));
    }

    public function test_low_success_rate_with_enough_sample_blocks_the_type(): void
    {
        $this->seedCommands(['executed', 'rolled_back', 'rolled_back', 'rolled_back', 'executed']);

        (new LearningLoop(siteId: $this->site->id))->handle();

        $this->assertDatabaseHas('automation_learning_history', [
            'site_id' => $this->site->id, 'command_type' => 'update_meta_title',
            'total' => 5, 'successful' => 2, 'blocked' => true,
        ]);
    }

    public function test_successful_type_is_not_blocked(): void
    {
        $this->seedCommands(['executed', 'executed', 'executed', 'executed']);

        (new LearningLoop(siteId: $this->site->id))->handle();

        $this->assertDatabaseHas('automation_learning_history', [
            'site_id' => $this->site->id, 'command_type' => 'update_meta_title', 'blocked' => false,
        ]);
        $this->assertFalse(app(AdaptiveLearning::class)->isBlocked($this->site->id, 'update_meta_title'));
    }

    public function test_block_is_isolated_per_site_and_per_type(): void
    {
        $this->seedCommands(['rolled_back', 'rolled_back', 'rolled_back'], 'update_meta_title');
        $this->seedCommands(['executed', 'executed', 'executed'], 'update_content');

        (new LearningLoop(siteId: $this->site->id))->handle();

        $this->assertTrue(app(AdaptiveLearning::class)->isBlocked($this->site->id, 'update_meta_title'));
        $this->assertFalse(app(AdaptiveLearning::class)->isBlocked($this->site->id, 'update_content'));
    }

    public function test_auto_heal_after_two_consecutive_successes(): void
    {
        $this->seedCommands(['executed', 'rolled_back', 'rolled_back', 'rolled_back']);
        (new LearningLoop(siteId: $this->site->id))->handle();
        $this->assertTrue(app(AdaptiveLearning::class)->isBlocked($this->site->id, 'update_meta_title'));

        // دو موفقیت متوالی بعد از مسدودیت → unblock (نرخ همچنان زیر آستانه است ولی auto-heal برنده است)
        $this->seedCommands(['executed', 'executed']);
        (new LearningLoop(siteId: $this->site->id))->handle();

        $this->assertDatabaseHas('automation_learning_history', [
            'site_id' => $this->site->id, 'command_type' => 'update_meta_title', 'blocked' => false,
        ]);
    }

    public function test_blocked_type_cannot_be_converted_to_command(): void
    {
        $this->seedCommands(['rolled_back', 'rolled_back', 'rolled_back']);
        (new LearningLoop(siteId: $this->site->id))->handle();

        $recommendation = Recommendation::create([
            'site_id' => $this->site->id, 'source_type' => 'manual', 'title' => 't', 'body' => 'b',
            'priority' => 'medium', 'status' => 'active',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('متوقف');

        app(ConvertRecommendationToCommand::class)->handle($recommendation, 'update_meta_title', 'https://e.ir/x', 'عنوان جدید');
    }

    public function test_risk_recommendations_are_suppressed_for_blocked_types(): void
    {
        $this->seedCommands(['rolled_back', 'rolled_back', 'rolled_back'], 'update_content');
        (new LearningLoop(siteId: $this->site->id))->handle();

        $profileId = \DB::table('url_profiles')->insertGetId([
            'site_id' => $this->site->id, 'public_id' => (string) \Illuminate\Support\Str::ulid(), 'canonical_url' => 'https://e.ir/a', 'content_type' => 'page', 'post_status' => 'publish',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('conversion_risks')->insert([
            'url_profile_id' => $profileId, 'key' => 'thin_content', 'score' => 0.85,
            'explanation' => 'محتوا کم است', 'severity' => 'high', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(CreateRiskRecommendations::class)->handle($this->site);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['suppressed']['thin_content'] ?? 0);
        $this->assertDatabaseMissing('recommendations', ['site_id' => $this->site->id]);
    }

    public function test_non_blocked_risk_recommendations_are_created(): void
    {
        $profileId = \DB::table('url_profiles')->insertGetId([
            'site_id' => $this->site->id, 'public_id' => (string) \Illuminate\Support\Str::ulid(), 'canonical_url' => 'https://e.ir/a', 'content_type' => 'page', 'post_status' => 'publish',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('conversion_risks')->insert([
            'url_profile_id' => $profileId, 'key' => 'thin_content', 'score' => 0.85,
            'explanation' => 'محتوا کم است', 'severity' => 'high', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(CreateRiskRecommendations::class)->handle($this->site);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('recommendations', ['site_id' => $this->site->id, 'source_type' => 'conversion_risk']);
    }

    public function test_learning_blocked_factor_appears_in_confidence_factors(): void
    {
        $this->seedCommands(['rolled_back', 'rolled_back', 'rolled_back']);
        (new LearningLoop(siteId: $this->site->id))->handle();

        $recommendation = Recommendation::create([
            'site_id' => $this->site->id, 'source_type' => 'manual', 'title' => 't', 'body' => 'b',
            'priority' => 'medium', 'status' => 'active',
        ]);

        $factors = app(\App\Domains\Automation\Services\CommandConfidenceAssessor::class)
            ->assess($recommendation, 'update_meta_title')['factors'];

        $this->assertTrue($factors['learning_blocked']);
    }
}