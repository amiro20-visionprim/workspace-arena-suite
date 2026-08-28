<?php

declare(strict_types=1);

return [
    // نسخهٔ محصول (SemVer) — با هر ریلیز به‌روز شود؛ CHANGELOG.md ریشه را ببینید
    'version' => '1.0.0-rc1',
    'default_locale' => env('VISION_PRIME_DEFAULT_LOCALE', 'fa'),
    'default_timezone' => env('VISION_PRIME_DEFAULT_TIMEZONE', 'Asia/Tehran'),

    /* سقف‌های دورهٔ بدون اشتراک (trial پیش‌فرض) */
    /* سقف پیش‌فرض تصاویر AI در ماه وقتی پلن مقدار ندارد */
    'images_monthly_default' => env('IMAGES_MONTHLY_DEFAULT', 10),

    /* سقف روزانهٔ درخواست تولید AI به ازای هر سازمان (محافظت هزینه) */
    'ai_daily_per_org' => env('AI_DAILY_PER_ORG', 50),

    'trial_limits' => [
        'max_sites' => 1,
        'max_clients' => 2,
        'max_ai_tokens_monthly' => 100_000,
        'max_profiles' => 3,
    ],
];
