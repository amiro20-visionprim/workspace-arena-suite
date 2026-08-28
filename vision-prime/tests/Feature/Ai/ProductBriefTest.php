<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

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
 * استودیوی محصول v2 (فاز C): بریف محصول با بافت ووکامرس،
 * دسته‌های محصول امضاشده و رسیدن terms تا فرمان انتشار.
 */
class ProductBriefTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'pb-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $this->admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $this->org->id, 'user_id' => $this->admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
        $client = Client::query()->create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $this->org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $this->site = Site::query()->create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://pb.ir', 'status' => 'active']);
    }

    private function connect(): void
    {
        DB::table('site_connections')->insert([
            'site_id' => $this->site->id, 'status' => 'connected', 'platform_url' => 'https://wp.test',
            'secret_ciphertext' => Crypt::encryptString('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_product_brief_includes_live_woo_context(): void
    {
        $this->connect();
        Http::fake(['*wp.test/wp-json/vision-prime/v1/product-info*' => Http::response([
            'post_id' => 88, 'title' => 'سرم شب', 'post_type' => 'product', 'url' => 'https://pb.ir/product/serum/',
            'is_product' => true, 'price' => '2450000', 'regular_price' => '2900000', 'sale_price' => '2450000',
            'currency' => 'IRR', 'stock_quantity' => 12, 'stock_status' => 'instock', 'in_stock' => true,
        ], 200)]);

        $res = $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->postJson('/api/content/brief', [
                'site_id' => $this->site->id, 'title' => 'سرم شب شفا', 'content_type' => 'product',
                'product_url' => 'https://pb.ir/product/serum/',
            ])->assertOk();

        $res->assertJsonPath('success', true)->assertJsonPath('brief.content_type', 'product');
        Assert::assertSame('2450000', (string) $res->json('brief.woo.price'));
        Assert::assertTrue((bool) $res->json('brief.woo.in_stock'));
    }

    public function test_product_taxonomies_returned_for_type_product(): void
    {
        $this->connect();
        Http::fake(['*wp.test/wp-json/vision-prime/v1/taxonomies*' => Http::response([
            'categories' => [['id' => 5, 'name' => 'بلاگ', 'slug' => 'blog', 'count' => 1]],
            'tags' => [],
            'product_cats' => [['id' => 21, 'name' => 'مراقبت پوست', 'slug' => 'skin-care', 'count' => 6]],
            'product_tags' => [['id' => 22, 'name' => 'سرم', 'slug' => 'serum', 'count' => 3]],
        ], 200)]);

        $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->getJson("/app/sites/{$this->site->id}/taxonomies?type=product")
            ->assertOk()
            ->assertJsonPath('product_cats.0.name', 'مراقبت پوست')
            ->assertJsonPath('product_tags.0.count', 3);
    }

    public function test_selected_terms_reach_the_publish_command(): void
    {
        $this->connect();
        Http::fake(['*wp-json/vision-prime/v1/commands*' => Http::response(['status' => 'ack'], 200)]);

        $draft = ContentDraft::query()->create([
            'site_id' => $this->site->id, 'title' => 'سرم شب', 'content' => '<p>x</p>',
            'subtype' => 'product', 'status' => 'draft',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->postJson('/api/content/publish-stored', [
                'draft_id' => $draft->id, 'status' => 'draft',
                'categories' => [21, 'مراقبت پوست'], 'tags' => ['سرم'],
            ])->assertOk()->assertJsonPath('success', true);

        $command = DB::table('commands')->where('source_type', 'content_draft')->where('source_id', $draft->id)->first();
        Assert::assertNotNull($command);
        $payload = json_decode((string) $command->payload, true)['payload'] ?? [];
        Assert::assertSame([21, 'مراقبت پوست'], $payload['categories'] ?? null, 'دسته‌ها باید در فرمان باشند');
        Assert::assertSame(['سرم'], $payload['tags'] ?? null, 'برچسب‌ها باید در فرمان باشند');
    }

    public function test_woo_info_endpoint_validates_and_scopes(): void
    {
        $this->connect();
        Http::fake(['*wp.test/wp-json/vision-prime/v1/product-info*' => Http::response([
            'is_product' => false,
        ], 200)]);

        $this->actingAs($this->admin)
            ->withSession(['current_organization_id' => $this->org->id])
            ->postJson('/api/content/woo-info', ['site_id' => $this->site->id, 'url' => 'https://pb.ir/product/nope/'])
            ->assertOk()
            ->assertJsonPath('success', false);
    }
}
