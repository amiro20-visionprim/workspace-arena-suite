<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * گارد CI (پیشنهاد فری‌باف — رفع چشم‌بستگی باگ‌های ۱-۴):
 * هر مسیر با کنترلر کلاس-محور باید قابل resolve باشد — یعنی نام کلاس‌ها در
 * type-hintهای constructor معتبر و importها کامل باشند. باگ تفکیک God Controller
 * (نوع «نام متغیر به‌جای کلاس») فقط موقع اجرای endpoint فعال می‌شد و از تست‌های
 * معمولی پنهان ماند؛ این تست از طریق container هر کنترلر را instantiate می‌کند.
 */
class AllControllersResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_routed_controller_can_be_instantiated(): void
    {
        $controllers = collect(RouteFacade::getRoutes())
            ->map(fn (Route $route) => $route->getControllerClass())
            ->filter(fn ($class) => is_string($class) && str_starts_with((string) $class, 'App\\'))
            ->unique()
            ->values();

        $this->assertGreaterThan(
            60,
            $controllers->count(),
            'بیش از ۶۰ کنترلر انتظار می‌رود — اگر کمتر است چک‌لیست مسیرها خراب است.',
        );

        $failures = [];
        foreach ($controllers as $class) {
            try {
                app($class);
            } catch (\Throwable $e) {
                $failures[] = "{$class} → ".mb_substr($e->getMessage(), 0, 140);
            }
        }

        $this->assertSame(
            [],
            $failures,
            "کنترلرهای غیرقابل resolve (باگ type-hint/import):\n".implode("\n", $failures),
        );
    }

    public function test_every_route_action_method_exists(): void
    {
        $missing = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getAction('controller');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            if (! str_starts_with($class, 'App\\')) {
                continue;
            }
            if (! method_exists($class, $method) && ! in_array($method, ['__invoke'], true)) {
                $missing[] = "{$action}";
            }
        }

        $this->assertSame([], $missing, "متدهای اکشن ناموجود:\n".implode("\n", $missing));
    }
}
