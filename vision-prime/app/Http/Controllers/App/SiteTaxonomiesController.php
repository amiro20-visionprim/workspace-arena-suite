<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Connector\Services\SignedConnectorClient;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * دسته‌ها و برچسب‌های واقعی سایتِ وردپرسی (امضاشده) + کش ۱۰ دقیقه‌ای.
 * سوختِ انتخابگر دسته/برچسب در استودیوی محتوا v2.
 */
class SiteTaxonomiesController extends Controller
{
    public function __invoke(Request $request, Site $site, CurrentOrganization $org): JsonResponse
    {
        if ($site->organization_id !== $org->id()) {
            abort(404);
        }

        $connection = DB::table('site_connections')
            ->where('site_id', $site->id)
            ->where('status', 'connected')
            ->first();

        if ($connection === null) {
            return response()->json([
                'success' => false,
                'connected' => false,
                'error' => 'پلاگین وردپرس برای این سایت جفت نشده است — دسته‌ها بعد از اتصال قابل انتخاب‌اند.',
            ]);
        }

        $cacheKey = "site-taxonomies:{$site->id}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json(['success' => true, 'connected' => true] + $cached);
        }

        try {
            $data = app(SignedConnectorClient::class)->get($connection, '/vision-prime/v1/taxonomies');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'connected' => true,
                'error' => 'دریافت دسته‌ها از وردپرس ناموفق بود: '.mb_substr($e->getMessage(), 0, 150),
            ]);
        }

        $map = fn (array $rows): array => array_values(array_map(fn ($c): array => [
            'id' => (int) $c['id'], 'name' => (string) $c['name'], 'slug' => (string) $c['slug'], 'count' => (int) ($c['count'] ?? 0),
        ], $rows));

        $payload = [
            'categories' => $map((array) ($data['categories'] ?? [])),
            'tags' => $map((array) ($data['tags'] ?? [])),
        ];
        if (($request->query('type') === 'product')) {
            $payload['product_cats'] = $map((array) ($data['product_cats'] ?? []));
            $payload['product_tags'] = $map((array) ($data['product_tags'] ?? []));
        }

        Cache::put($cacheKey, $payload, now()->addMinutes(10));

        return response()->json(['success' => true, 'connected' => true] + $payload);
    }
}
