<?php

declare(strict_types=1);

namespace App\Domains\Seo\Actions;

use App\Domains\Automation\Services\AdaptiveLearning;
use App\Domains\Workspace\Models\Site;

class CreateRiskRecommendations
{
    /**
     * @return array{created: int, suppressed: array<string, int>}
     */
    public function handle(Site $site): array
    {
        $risks = \DB::table('conversion_risks')
            ->join('url_profiles', 'url_profiles.id', '=', 'conversion_risks.url_profile_id')
            ->where('url_profiles.site_id', $site->id)
            ->get(['conversion_risks.*', 'url_profiles.canonical_url']);

        $adaptive = app(AdaptiveLearning::class);
        $created = 0;
        $suppressed = [];

        foreach ($risks as $risk) {
            // Map risk key to command type for adaptive learning check
            $commandType = match ($risk->key) {
                'thin_content' => 'update_content',
                'weak_cta' => 'update_meta_description',
                'unclear_offer' => 'update_meta_title',
                default => 'update_content',
            };

            // Suppress if this command type is blocked for this site
            if ($adaptive->isBlocked((int) $site->id, $commandType)) {
                $suppressed[$risk->key] = ($suppressed[$risk->key] ?? 0) + 1;
                continue;
            }

            $title = match ($risk->key) {
                'thin_content' => 'افزایش عمق محتوای صفحه',
                'weak_cta' => 'افزودن CTA واضح',
                'unclear_offer' => 'شفاف‌سازی پیشنهاد صفحه',
                default => 'رفع ریسک تبدیل',
            };

            \DB::table('recommendations')->updateOrInsert(
                ['site_id' => $site->id, 'source_type' => 'conversion_risk', 'source_id' => $risk->id],
                [
                    'title' => $title,
                    'body' => $risk->explanation . ' URL: ' . $risk->canonical_url,
                    'priority' => $risk->severity === 'high' ? 'high' : 'medium',
                    'status' => 'draft',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $created++;
        }

        return ['created' => $created, 'suppressed' => $suppressed];
    }
}
