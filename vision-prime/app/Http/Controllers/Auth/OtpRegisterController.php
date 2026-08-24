<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * دریافت کد تأیید ثبت‌نام (OTP) برای شمارهٔ تماس.
 *
 * قرارداد پاسخ (JSON):
 *  - 200 → { sent: true,  message, code? }  (code فقط در محیط توسعه/تست برگردانده می‌شود)
 *  - 422 → { sent: false, message }  (شمارهٔ قبلاً ثبت‌شده یا ورودی نامعتبر)
 *  - 502 → { sent: false, message }  (ارسال پیامک ناموفق)
 */
class OtpRegisterController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^0?9[0-9]{9}$/'],
        ], [
            'phone.required' => 'شماره تماس را وارد کنید.',
            'phone.regex' => 'شماره تماس معتبر نیست (مثال: 09123456789).',
        ]);

        $phone = OtpService::normalizePhone((string) $validated['phone']);

        // جلوگیری از سوءاستفاده (SMS bombing) روی یک شماره — مستقل از throttle مسیر.
        $throttleKey = 'otp-register:'.$phone.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'sent' => false,
                'message' => 'درخواست بیش از حد؛ چند دقیقهٔ دیگر دوباره تلاش کنید.',
            ], 429);
        }

        // شمارهٔ قبلاً ثبت‌شده را زود رد کن (UX بهتر + جلوگیری از هدر رفتن پیامک).
        if (User::query()->where('phone', $phone)->exists()) {
            return response()->json([
                'sent' => false,
                'message' => 'این شماره تماس قبلاً ثبت شده است.',
            ], 422);
        }

        RateLimiter::hit($throttleKey, 60);

        $result = app(OtpService::class)->request($phone, 'register');

        return response()->json($result, $result['sent'] ? 200 : 502);
    }
}
