<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Platform\Services\PlanLimits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * اعمال سقف‌های پلن روی مسیرهای ساخت منبع.
 *
 * کاربرد در route: ->middleware('plan.limits:sites') یا :clients یا :ai
 *   - sites/clients: قبل از ساخت ردیف جدید شمارش می‌شود.
 *   - ai:            سهمیهٔ ماهانهٔ توکن کنترل می‌شود.
 *
 * خطا به‌صورت ValidationException برمی‌گردد تا هم فرم Inertia پیام فارسی
 * ببیند و هم API کلاینت‌ها JSON منظم بگیرند.
 */
class EnforcePlanLimits
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly CurrentOrganization $org,
    ) {}

    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $organization = $this->org->get();

        // بدون سازمان فعلی (مثلاً مسیرهای عمومی) محدودیتی نداریم.
        if ($organization === null) {
            return $next($request);
        }

        $message = match ($resource) {
            'sites' => $this->limits->canCreate($organization, 'sites'),
            'clients' => $this->limits->canCreate($organization, 'clients'),
            'ai' => $this->limits->aiQuotaError($organization),
            'images' => $this->limits->imageQuotaError($organization),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['plan_limit' => $message]);
        }

        return $next($request);
    }
}
