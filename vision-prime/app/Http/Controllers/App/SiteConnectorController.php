<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Content\Services\WordPressPublisher;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SiteConnectorController extends Controller
{
    public function show(Site $site): Response
    {
        Gate::authorize('view', $site);
        $connection = \DB::table('site_connections')->where('site_id', $site->id)->first();
        $settings = (array) $site->settings;
        $wpCreds = $settings['wordpress'] ?? null;

        return Inertia::render('App/Sites/Connector', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'canonicalUrl' => $site->canonical_url],
            'connection' => $connection === null ? null : [
                'status' => $connection->status,
                'platformUrl' => $connection->platform_url,
                'pluginVersion' => $connection->plugin_version,
                'lastSeenAt' => $connection->last_seen_at,
                'health' => json_decode($connection->health ?? '{}', true),
            ],
            'wpCredentials' => $wpCreds ? [
                'wp_url' => $wpCreds['wp_url'] ?? '',
                'wp_username' => $wpCreds['wp_username'] ?? '',
                'has_password' => ! empty($wpCreds['wp_app_password']),
                'connected_at' => $wpCreds['connected_at'] ?? null,
            ] : null,
        ]);
    }

    /**
     * Save WordPress API credentials for publishing.
     * POST /app/sites/{site}/connector/wp-credentials
     */
    public function saveWpCredentials(Site $site, CurrentOrganization $c): JsonResponse
    {
        Gate::authorize('update', $site);

        $data = request()->validate([
            'wp_url' => 'required|string|max:500',
            'wp_username' => 'required|string|max:200',
            'wp_app_password' => 'required|string|max:200',
        ]);

        // Normalize URL
        $data['wp_url'] = rtrim($data['wp_url'], '/');

        // Test connection first
        $publisher = app(WordPressPublisher::class);
        $test = $publisher->testConnection($data['wp_url'], $data['wp_username'], $data['wp_app_password']);

        if (! $test['success']) {
            return response()->json([
                'success' => false,
                'error' => 'اتصال به وردپرس ناموفق بود: '.($test['error'] ?? 'اطلاعات صحیح نیست'),
            ], 422);
        }

        // Save to site settings
        $settings = (array) $site->settings;
        $settings['wordpress'] = [
            'wp_url' => $data['wp_url'],
            'wp_username' => $data['wp_username'],
            'wp_app_password' => $data['wp_app_password'],
            'connected_at' => now()->toISOString(),
            'user_name' => $test['user'] ?? '',
        ];
        $site->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات وردپرس ذخیره شد.',
            'user_name' => $test['user'] ?? '',
        ]);
    }

    /**
     * Remove WordPress credentials.
     * DELETE /app/sites/{site}/connector/wp-credentials
     */
    public function removeWpCredentials(Site $site, CurrentOrganization $c): JsonResponse
    {
        Gate::authorize('update', $site);

        $settings = (array) $site->settings;
        unset($settings['wordpress']);
        $site->update(['settings' => $settings]);

        return response()->json(['success' => true, 'message' => 'اطلاعات وردپرس حذف شد.']);
    }

    /**
     * قطع اتصال سایت از وردپرس — فقط ادمین و سوپر ادمین.
     * اتصال (site_connections)، توکن‌های pairing، و url_profiles پاک میشن.
     * خود سایت حذف نمیشه.
     */
    public function disconnect(Site $site, CurrentOrganization $c): RedirectResponse
    {
        Gate::authorize('delete', $site);

        $user = request()->user();
        $isAdmin = $user?->memberships()->where('status', 'active')->with('role')->get()->contains(fn ($m) => in_array($m->role?->key, ['agency-admin', 'super-admin'])) ?? false;
        if ($user !== null && ! $user->isSuperAdmin() && ! $isAdmin) {
            abort(403, 'فقط مدیر سیستم یا ادمین می‌تواند اتصال را قطع کند.');
        }

        \DB::transaction(function () use ($site): void {
            \DB::table('url_profiles')->where('site_id', $site->id)->delete();
            \DB::table('keyword_insights')->where('site_id', $site->id)->delete();
            \DB::table('content_drafts')->where('site_id', $site->id)->delete();
            \DB::table('site_connector_tokens')->where('site_id', $site->id)->delete();
            \DB::table('site_connections')->where('site_id', $site->id)->delete();
        });

        return back()->with('status', 'اتصال سایت قطع شد. داده‌های همگام‌سازی حذف شدند.');
    }
}
