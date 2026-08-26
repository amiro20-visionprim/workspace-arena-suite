<?php

declare(strict_types=1);

return [
    'default_locale' => env('VISION_PRIME_DEFAULT_LOCALE', 'fa'),
    'default_timezone' => env('VISION_PRIME_DEFAULT_TIMEZONE', 'Asia/Tehran'),

    /* سقف‌های دورهٔ بدون اشتراک (trial پیش‌فرض) */
    /* سقف روزانهٔ درخواست تولید AI به ازای هر سازمان (محافظت هزینه) */
    'ai_daily_per_org' => env('AI_DAILY_PER_ORG', 50),

    'trial_limits' => [
        'max_sites' => 1,
        'max_clients' => 2,
        'max_ai_tokens_monthly' => 100_000,
        'max_profiles' => 3,
    ],
];
