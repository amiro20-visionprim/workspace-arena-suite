<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Services\OrganizationPermission;

/**
 * هلپرهای مشترک کنترلرهای API محتوا (پیش از این در ContentApiController ۱۴۱۴خطی بودند).
 */
trait InteractsWithContentApi
{
    /**
     * فقط سوپر ادمین اجازه تولید محتوا با AI و مدیریت Provider رو داره.
     */
    private function authorizeSuperAdmin(): void
    {
        // RBAC یکدست با AiSettingsController (P0): ادمین سازمانِ دارای مجوز
        // ai.provider.manage.organization یا سوپرادمین پلتفرم.
        // پاسخ 403 همیشه JSON است تا فرانت «خطا: undefined» نشان ندهد.
        $user = request()->user();
        if ($user !== null && $user->isSuperAdmin()) {
            return;
        }

        $org = app(CurrentOrganization::class);
        $allowed = $user !== null
            && $org->has()
            && app(OrganizationPermission::class)
                ->allows($user, $org->get(), 'ai.provider.manage.organization');

        if (! $allowed) {
            abort(response()->json([
                'success' => false,
                'error' => 'برای مدیریت و تست سرویس‌های هوش مصنوعی دسترسی ندارید.',
            ], 403));
        }
    }

    private function extractHeadings(string $html): array
    {
        $headings = [];
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $headings[] = strip_tags($match[2]);
        }

        return $headings;
    }

    /**
     * Fetch real GSC metrics for a keyword from keyword_insights.
     */
    private function fetchGscMetrics(int $siteId, string $keyword): array
    {
        if ($keyword === '') {
            return ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0, 'related_queries' => []];
        }

        $insights = DB::table('keyword_insights')
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->where(function ($q) use ($keyword) {
                $words = array_filter(explode(' ', trim($keyword)), fn ($w) => mb_strlen($w) > 2);
                foreach ($words as $word) {
                    $q->orWhere('query_normalized', 'LIKE', '%'.$word.'%');
                }
            })
            ->orderByDesc('latest_metrics->clicks')
            ->limit(10)
            ->get();

        $totalClicks = 0;
        $totalImpressions = 0;
        $relatedQueries = [];

        foreach ($insights as $insight) {
            $m = json_decode($insight->latest_metrics, true) ?? [];
            $clicks = (int) ($m['clicks'] ?? 0);
            $impressions = (int) ($m['impressions'] ?? 0);
            $totalClicks += $clicks;
            $totalImpressions += $impressions;
            $relatedQueries[] = [
                'query' => $insight->query_normalized,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => round((float) ($m['ctr'] ?? 0) * 100, 2),
                'position' => round((float) ($m['position'] ?? 0), 1),
            ];
        }

        return [
            'clicks' => $totalClicks,
            'impressions' => $totalImpressions,
            'ctr' => $totalImpressions > 0 ? round($totalClicks / $totalImpressions * 100, 2) : 0,
            'position' => count($relatedQueries) > 0 ? round(array_sum(array_column($relatedQueries, 'position')) / count($relatedQueries), 1) : 0,
            'related_queries' => $relatedQueries,
        ];
    }
}
