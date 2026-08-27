<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * مهر زمانِ آخرین بازدید کاربر (users.last_seen_at) — سوخت شاخص
 * «کاربران فعال امروز» در app:health. حداکثر یک بار در ۱۰ دقیقه به‌ازای
 * کاربر نوشته می‌شود تا دیتابیس اضافه فشار نیاورد.
 */
class StampLastSeen
{
    private const THROTTLE_MINUTES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $key = "last_seen:{$user->id}";

            if (! Cache::has($key)) {
                Cache::put($key, true, now()->addMinutes(self::THROTTLE_MINUTES));

                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
