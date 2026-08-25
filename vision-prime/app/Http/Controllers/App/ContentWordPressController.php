<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Content\Models\ContentDraft;
use App\Domains\Content\Services\WordPressPublisher;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\App\Concerns\InteractsWithContentApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * انتشار و همگام‌سازی با وردپرس.
 *
 * (از تفکیک ContentApiController ۱۴۱۴خطی — رفتار و مسیرها بدون تغییر)
 */
class ContentWordPressController extends Controller
{
    use InteractsWithContentApi;

    /**
     * Publish article to WordPress.
     * POST /api/content/publish
     */
    public function publishToWordPress(Request $request, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'draft_id' => 'required|integer|exists:content_drafts,id',
            'status' => 'nullable|string|in:publish,draft,pending',
            'wp_url' => 'required|string|max:500',
            'wp_username' => 'required|string|max:200',
            'wp_app_password' => 'required|string|max:200',
        ]);

        $draft = ContentDraft::findOrFail($data['draft_id']);
        $publisher = app(WordPressPublisher::class);

        $result = $publisher->publish(
            [
                'wp_url' => $data['wp_url'],
                'wp_username' => $data['wp_username'],
                'wp_app_password' => $data['wp_app_password'],
            ],
            [
                'title' => $draft->title,
                'content' => $draft->content,
                'meta_title' => $draft->meta_title,
                'meta_description' => $draft->meta_description,
                'slug' => $draft->slug,
                'status' => $data['status'] ?? 'draft',
            ]
        );

        if ($result['success']) {
            $draft->update([
                'status' => 'published',
                'audit_log' => array_merge($draft->audit_log ?? [], [
                    'wp_post_id' => $result['post_id'],
                    'wp_post_url' => $result['post_url'],
                    'published_at' => now()->toISOString(),
                ]),
            ]);
        }

        return response()->json($result);
    }

    /**
     * Test WordPress connection.
     * POST /api/content/test-wp
     */
    public function testWordPress(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'wp_url' => 'required|string|max:500',
            'wp_username' => 'required|string|max:200',
            'wp_app_password' => 'required|string|max:200',
        ]);

        $publisher = app(WordPressPublisher::class);

        return response()->json($publisher->testConnection(
            $data['wp_url'], $data['wp_username'], $data['wp_app_password']
        ));
    }

    /**
     * Test WordPress connection for a site.
     * POST /api/content/test-wp-connection
     */
    public function testWpConnection(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
            'wp_url' => 'required|string|max:500',
            'wp_username' => 'required|string|max:200',
            'wp_app_password' => 'required|string|max:200',
        ]);

        $publisher = app(WordPressPublisher::class);
        $result = $publisher->testConnection($data['wp_url'], $data['wp_username'], $data['wp_app_password']);

        if ($result['success']) {
            $site = Site::findOrFail($data['site_id']);
            $settings = (array) $site->settings;
            $settings['wordpress'] = [
                'wp_url' => $data['wp_url'],
                'wp_username' => $data['wp_username'],
                'wp_app_password' => $data['wp_app_password'],
                'connected_at' => now()->toISOString(),
                'user_name' => $result['user'] ?? '',
            ];
            $site->update(['settings' => $settings]);
        }

        return response()->json($result);
    }

    /**
     * Publish draft to WordPress using stored credentials.
     * POST /api/content/publish-stored
     */
    public function publishStored(Request $request, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'draft_id' => 'required|integer|exists:content_drafts,id',
            'status' => 'nullable|string|in:publish,draft,pending',
        ]);

        $draft = ContentDraft::findOrFail($data['draft_id']);
        $site = Site::findOrFail($draft->site_id);
        $settings = (array) $site->settings;

        // 1. Try site->settings->wordpress (stored credentials)
        $wp = $settings['wordpress'] ?? null;

        // 2. If not found, try site_connections + settings combo
        if (empty($wp['wp_url']) || empty($wp['wp_username'])) {
            $conn = \DB::table('site_connections')->where('site_id', $site->id)->where('status', 'connected')->first();
            if ($conn && ! empty($conn->platform_url)) {
                $wp = [
                    'wp_url' => $conn->platform_url,
                    'wp_username' => $wp['wp_username'] ?? '',
                    'wp_app_password' => $wp['wp_app_password'] ?? '',
                ];
            }
        }

        // 3. Validate credentials
        if (! $wp || empty($wp['wp_url']) || empty($wp['wp_username'])) {
            return response()->json([
                'success' => false,
                'error' => 'تنظیمات وردپرس ذخیره نشده. ابتدا از صفحه «اتصال وردپرس» اطلاعات WP را وارد کنید.',
                'needs_setup' => true,
                'setup_url' => "/app/sites/{$site->id}/connector",
            ]);
        }

        $publisher = app(WordPressPublisher::class);
        $result = $publisher->publish(
            ['wp_url' => $wp['wp_url'], 'wp_username' => $wp['wp_username'], 'wp_app_password' => $wp['wp_app_password'] ?? ''],
            ['title' => $draft->title, 'content' => $draft->content, 'meta_title' => $draft->meta_title, 'meta_description' => $draft->meta_description, 'slug' => $draft->slug, 'status' => $data['status'] ?? 'draft']
        );

        if ($result['success']) {
            $draft->update(['status' => 'published', 'audit_log' => array_merge($draft->audit_log ?? [], ['wp_post_id' => $result['post_id'], 'wp_post_url' => $result['post_url'], 'published_at' => now()->toISOString()])]);
        }

        return response()->json($result);
    }

    /**
     * Sync content from WordPress REST API to url_profiles.
     * This enables internal link suggestions.
     *
     * POST /api/content/sync-wordpress
     */
    public function syncWordPressContent(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'site_id' => 'required|integer',
        ]);

        $site = Site::query()->where('organization_id', $org->id())->findOrFail($data['site_id']);

        // Get WordPress URL from site settings or connection
        $wpUrl = $site->canonical_url ?? '';
        if (empty($wpUrl)) {
            return response()->json(['error' => 'آدرس سایت وردپرس تنظیم نشده است.'], 422);
        }

        $baseUrl = rtrim($wpUrl, '/');
        $synced = 0;
        $errors = [];
        $categories = [];
        $tags = [];

        // --- 1. Sync Categories ---
        try {
            $response = Http::timeout(30)
                ->get($baseUrl.'/wp-json/wp/v2/categories', ['per_page' => 100, '_fields' => 'id,name,slug,parent,count']);
            if ($response->successful()) {
                foreach ($response->json() as $cat) {
                    $catUrl = $baseUrl.'/'.$cat['slug'].'/';
                    $categories[$cat['id']] = ['name' => $cat['name'], 'slug' => $cat['slug'], 'count' => $cat['count'] ?? 0];
                    $this->upsertUrlProfile($site->id, $catUrl, 'category', $cat['slug'], $cat['name'], (int) $cat['id'], now()->toDateTimeString(), ['name' => $cat['name'], 'slug' => $cat['slug'], 'count' => $cat['count'] ?? 0]);
                    $synced++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'categories: '.$e->getMessage();
        }

        // --- 2. Sync Tags ---
        try {
            $response = Http::timeout(30)
                ->get($baseUrl.'/wp-json/wp/v2/tags', ['per_page' => 100, '_fields' => 'id,name,slug,count']);
            if ($response->successful()) {
                foreach ($response->json() as $tag) {
                    $tagUrl = $baseUrl.'/tag/'.$tag['slug'].'/';
                    $tags[$tag['id']] = ['name' => $tag['name'], 'slug' => $tag['slug'], 'count' => $tag['count'] ?? 0];
                    $this->upsertUrlProfile($site->id, $tagUrl, 'tag', $tag['slug'], $tag['name'], (int) $tag['id'], now()->toDateTimeString(), ['name' => $tag['name'], 'slug' => $tag['slug'], 'count' => $tag['count'] ?? 0]);
                    $synced++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'tags: '.$e->getMessage();
        }

        // --- 3. Sync Posts (with category/tag metadata) ---
        try {
            $response = Http::timeout(30)
                ->get(rtrim($wpUrl, '/').'/wp-json/wp/v2/posts', [
                    'per_page' => 100,
                    '_fields' => 'id,title,link,modified,slug,categories,tags,excerpt',
                ]);

            if ($response->successful()) {
                foreach ($response->json() as $post) {
                    $title = is_array($post['title']) ? ($post['title']['rendered'] ?? '') : $post['title'];
                    $url = $post['link'] ?? '';
                    if (empty($url)) {
                        continue;
                    }

                    // Resolve category and tag names
                    $postCatNames = [];
                    foreach (($post['categories'] ?? []) as $catId) {
                        if (isset($categories[$catId])) {
                            $postCatNames[] = $categories[$catId]['name'];
                        }
                    }
                    $postTagNames = [];
                    foreach (($post['tags'] ?? []) as $tagId) {
                        if (isset($tags[$tagId])) {
                            $postTagNames[] = $tags[$tagId]['name'];
                        }
                    }
                    $excerpt = isset($post['excerpt']['rendered']) ? strip_tags($post['excerpt']['rendered']) : '';

                    $metadata = [
                        'title' => $title,
                        'wp_id' => $post['id'],
                        'categories' => $postCatNames,
                        'tags' => $postTagNames,
                        'category_ids' => $post['categories'] ?? [],
                        'tag_ids' => $post['tags'] ?? [],
                        'excerpt' => mb_substr($excerpt, 0, 300),
                    ];

                    $existing = DB::table('url_profiles')
                        ->where('site_id', $site->id)
                        ->where('canonical_url', $url)
                        ->first();
                    if ($existing) {
                        DB::table('url_profiles')->where('id', $existing->id)->update([
                            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                            'updated_at' => $post['modified'] ?? now(),
                        ]);
                    } else {
                        DB::table('url_profiles')->insert([
                            'site_id' => $site->id,
                            'public_id' => Str::ulid(),
                            'canonical_url' => $url,
                            'content_type' => 'post',
                            'post_status' => 'publish',
                            'slug' => $post['slug'] ?? '',
                            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                            'created_at' => now(),
                            'updated_at' => $post['modified'] ?? now(),
                        ]);
                    }
                    $synced++;
                    $synced++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'posts: '.$e->getMessage();
        }

        // Sync products (WooCommerce)
        try {
            $response = Http::timeout(30)
                ->get(rtrim($wpUrl, '/').'/wp-json/wc/v3/products', [
                    'per_page' => 100,
                    '_fields' => 'id,name,permalink,date_modified',
                ]);

            if ($response->successful()) {
                foreach ($response->json() as $product) {
                    $url = $product['permalink'] ?? '';
                    if (empty($url)) {
                        continue;
                    }

                    $this->upsertUrlProfile($site->id, $url, 'product', Str::slug($product['name'] ?? ''), $product['name'] ?? '', $product['id'], $product['date_modified'] ?? now()->toDateTimeString());
                    $synced++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'products: '.$e->getMessage();
        }

        // Sync pages
        try {
            $response = Http::timeout(30)
                ->get(rtrim($wpUrl, '/').'/wp-json/wp/v2/pages', [
                    'per_page' => 100,
                    '_fields' => 'id,title,link,modified,type',
                ]);

            if ($response->successful()) {
                foreach ($response->json() as $page) {
                    $title = is_array($page['title']) ? ($page['title']['rendered'] ?? '') : $page['title'];
                    $url = $page['link'] ?? '';
                    if (empty($url)) {
                        continue;
                    }

                    $this->upsertUrlProfile($site->id, $url, 'page', $page['slug'] ?? '', $title, $page['id'], $page['modified'] ?? now()->toDateTimeString());
                    $synced++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'pages: '.$e->getMessage();
        }

        $totalProfiles = DB::table('url_profiles')->where('site_id', $site->id)->count();
        $byType = DB::table('url_profiles')->where('site_id', $site->id)
            ->selectRaw('content_type, count(*) as cnt')
            ->groupBy('content_type')
            ->pluck('cnt', 'content_type')
            ->toArray();

        return response()->json([
            'synced' => $synced,
            'errors' => $errors,
            'url_profiles_count' => $totalProfiles,
            'by_type' => $byType,
            'categories_count' => count($categories),
            'tags_count' => count($tags),
        ]);
    }

    /**
     * Research topics from GSC data — opportunities, keyword gaps, high-potential topics.
     *
     * GET /api/content/research?site_id=1
     */
    private function upsertUrlProfile(int $siteId, string $url, string $contentType, string $slug, string $title, int $wpId, string $modifiedAt, array $extra = []): void
    {
        $metadata = array_merge(['title' => $title, 'wp_id' => $wpId], $extra);
        $existing = DB::table('url_profiles')
            ->where('site_id', $siteId)
            ->where('canonical_url', $url)
            ->first();

        if ($existing) {
            DB::table('url_profiles')->where('id', $existing->id)->update([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'updated_at' => $modifiedAt,
            ]);
        } else {
            DB::table('url_profiles')->insert([
                'site_id' => $siteId,
                'public_id' => Str::ulid(),
                'canonical_url' => $url,
                'content_type' => $contentType,
                'post_status' => 'publish',
                'slug' => $slug,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => $modifiedAt,
            ]);
        }
    }
}
