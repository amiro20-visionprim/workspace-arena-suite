<?php

declare(strict_types=1);

namespace App\Domains\Reporting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * قدم ۱ اکوسیستم — DataBridge: دریافت و ذخیرهٔ تلمتری ترافیک.
 *
 * داده از دو مسیر می‌آید:
 *  1. پلاگین وردپرس (شمارش سمت سرور + گزارش روزانه امضاشده) — مسیر اصلی در شرایط نت ملی
 *  2. Matomo سلف‌هاست (اگر نصب باشد) — آداپتور اختیاری با دادهٔ غنی‌تر
 *
 * قرارداد upsert: یک URL × یک روز × یک منبع فقط یک ردیف دارد؛ ارسال‌های تکراری
 * (retry پلاگین) تجمعی جمع می‌شوند نه جایگزینی — چون پلاگین ساعت به ساعت ارسال
 * جزئی می‌فرستد (incremental) و در پایان روز جمع کل باید درست باشد.
 * برای جلوگیری از دوبار شمردن در retry، پلاگین batch_id می‌فرستد؛ همان batch
 * دوباره نیاید (site_traffic_ingest_log).
 */
class TrafficIngestService
{
    /**
     * ثبت دادهٔ ترافیک از پلاگین/آداپتور.
     *
     * @param  array<int, array{page_url:string,date:string,views:int,visitors?:int,entrances?:int,search_impressions?:int|null,search_clicks?:int|null,avg_position?:float|null}>  $rows
     * @param  string  $source  plugin|matomo
     * @param  string|null  $batchId  شناسهٔ یکتای این batch برای idempotency
     * @return array{accepted:int, duplicates:int, rows:int}
     */
    public function ingest(int $siteId, array $rows, string $source = 'plugin', ?string $batchId = null): array
    {
        // idempotency در سطح batch — اگر همین batch قبلاً خورده شده، دوباره جمع نمی‌شود.
        if ($batchId !== null && $batchId !== '') {
            $exists = DB::table('site_traffic_ingest_log')
                ->where('site_id', $siteId)
                ->where('batch_id', $batchId)
                ->exists();
            if ($exists) {
                Log::info('TrafficIngest: duplicate batch ignored', ['site_id' => $siteId, 'batch_id' => $batchId]);

                return ['accepted' => 0, 'duplicates' => count($rows), 'rows' => 0];
            }
        }

        $accepted = 0;
        $now = now();

        DB::transaction(function () use ($siteId, $rows, $source, $batchId, $now, &$accepted): void {
            foreach ($rows as $row) {
                $url = $this->normalizeUrl((string) ($row['page_url'] ?? ''));
                if ($url === '') {
                    continue;
                }

                $date = $this->normalizeDate((string) ($row['date'] ?? ''));
                if ($date === null) {
                    continue;
                }

                $views = max(0, (int) ($row['views'] ?? 0));
                $visitors = max(0, (int) ($row['visitors'] ?? 0));
                $entrances = max(0, (int) ($row['entrances'] ?? 0));
                $impr = isset($row['search_impressions']) && $row['search_impressions'] !== null ? max(0, (int) $row['search_impressions']) : null;
                $clicks = isset($row['search_clicks']) && $row['search_clicks'] !== null ? max(0, (int) $row['search_clicks']) : null;
                $position = isset($row['avg_position']) && $row['avg_position'] !== null ? round((float) $row['avg_position'], 2) : null;

                // URL کامل یا نسبی پذیرفته می‌شود؛ همیشه به شکل نسبی/کانونی ذخیره می‌کنیم
                // تا با page_url در gsc_page_metrics و url_profiles هم‌خوان باشد.
                $existing = DB::table('site_traffic_daily')
                    ->where('site_id', $siteId)
                    ->where('page_url', $url)
                    ->where('date', $date)
                    ->where('source', $source)
                    ->first();

                if ($existing === null) {
                    DB::table('site_traffic_daily')->insert([
                        'site_id' => $siteId,
                        'page_url' => $url,
                        'date' => $date,
                        'source' => $source,
                        'views' => $views,
                        'visitors' => $visitors,
                        'entrances' => $entrances,
                        'search_impressions' => $impr,
                        'search_clicks' => $clicks,
                        'avg_position' => $position,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    // ارسال incremental: جمع تجمعی. visitor/position ماکسیمم می‌مانند (تجمعی معنا ندارند).
                    DB::table('site_traffic_daily')
                        ->where('id', $existing->id)
                        ->update([
                            'views' => (int) $existing->views + $views,
                            'visitors' => max((int) $existing->visitors, $visitors),
                            'entrances' => (int) $existing->entrances + $entrances,
                            'search_impressions' => $impr !== null && $existing->search_impressions !== null
                                ? (int) $existing->search_impressions + $impr
                                : ($impr ?? $existing->search_impressions),
                            'search_clicks' => $clicks !== null && $existing->search_clicks !== null
                                ? (int) $existing->search_clicks + $clicks
                                : ($clicks ?? $existing->search_clicks),
                            'avg_position' => $position ?? $existing->avg_position,
                            'updated_at' => $now,
                        ]);
                }

                $accepted++;
            }

            if ($batchId !== null && $batchId !== '') {
                DB::table('site_traffic_ingest_log')->insert([
                    'site_id' => $siteId,
                    'batch_id' => $batchId,
                    'source' => $source,
                    'rows' => count($rows),
                    'created_at' => $now,
                ]);
            }
        });

        return ['accepted' => $accepted, 'duplicates' => count($rows) - $accepted, 'rows' => count($rows)];
    }

    /**
     * خواندن پنجرهٔ ترافیک یک URL برای گزارش تأثیر (fallback بدون GSC).
     *
     * @return array{days:int, views:int, visitors:int, entrances:int}
     */
    public function windowFor(int $siteId, string $url, Carbon $start, Carbon $end): array
    {
        $url = $this->normalizeUrl($url);

        $rows = DB::table('site_traffic_daily')
            ->where('site_id', $siteId)
            ->where('page_url', $url)
            ->where('source', 'plugin')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['date', 'views', 'visitors', 'entrances']);

        // اگر روز تکراری از چند منبع بود فقط plugin می‌آید (where source) — اینجا تجمیع روزها.
        $distinctDays = $rows->pluck('date')->unique()->count();
        $sumViews = (int) $rows->sum('views');
        $sumEntrances = (int) $rows->sum('entrances');
        // visitors را در پنجره جمع نمی‌کنیم (شمارش تکراری) — میانگین روزانه × روزها محافظه‌کارانه‌تر است.
        $avgVisitors = $rows->count() > 0 ? (int) round($rows->avg('visitors')) : 0;

        return [
            'days' => $distinctDays,
            'views' => $sumViews,
            'visitors' => $avgVisitors * $distinctDays,
            'entrances' => $sumEntrances,
        ];
    }

    /**
     * سری روزانهٔ views برای نمودار روند (هم‌قرارداد با series در BuildPublishImpactReport).
     *
     * @return array<int, array{date:string, views:int, entrances:int}>
     */
    public function seriesFor(int $siteId, string $url, Carbon $start, Carbon $end): array
    {
        $url = $this->normalizeUrl($url);

        $rows = DB::table('site_traffic_daily')
            ->where('site_id', $siteId)
            ->where('page_url', $url)
            ->where('source', 'plugin')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['date', 'views', 'entrances'])
            ->keyBy('date');

        $points = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $row = $rows->get($key);
            $points[] = [
                'date' => $key,
                'views' => $row !== null ? (int) $row->views : 0,
                'entrances' => $row !== null ? (int) $row->entrances : 0,
            ];
        }

        return $points;
    }

    /** آیا این سایت اصلاً دادهٔ تلمتری دارد؟ (برای تصمیم fallback) */
    public function hasData(int $siteId): bool
    {
        return DB::table('site_traffic_daily')->where('site_id', $siteId)->exists();
    }

    /** آخرین روزی که داده دارد — برای تشخیص پلاگینِ مرده. */
    public function lastDataDate(int $siteId): ?string
    {
        $v = DB::table('site_traffic_daily')->where('site_id', $siteId)->max('date');

        return $v !== null ? (string) $v : null;
    }

    /**
     * نرمال‌سازی URL: حذف scheme/host و trailing-slash یکسان.
     * «liuna.ir/product/x/» و «https://liuna.ir/product/x» هر دو → «/product/x»
     * (هم‌خوان با قرارداد page_url در جداول GSC/کرالر).
     */
    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        //scheme و host را جدا کن
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? ($url[0] === '/' ? $url : '/'.$url);
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        // پارامترهای ردیابی حذف — نویز نمی‌آورند
        if ($query !== '') {
            parse_str(ltrim($query, '?'), $params);
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'] as $noise) {
                unset($params[$noise]);
            }
            $query = $params !== [] ? '?'.http_build_query($params) : '';
        }

        $path = '/'.ltrim((string) $path, '/');
        // trailing slash را حذف کن مگر ریشه
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path.$query;
    }

    private function normalizeDate(string $date): ?string
    {
        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
