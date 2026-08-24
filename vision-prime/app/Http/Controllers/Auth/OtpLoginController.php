<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Identity\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ورود بدون رمز با کد یکبارمصرف (OTP).
 *
 * نکتهٔ امنیتی: مرحلهٔ «درخواست کد» وجودِ حساب را افشا نمی‌کند
 * (برای جلوگیری از شمارش شماره‌ها، پیامک برای هر شماره‌ای ارسال می‌شود)؛
 * تنها مرحلهٔ «تأیید» است که مشخص می‌کند حساب وجود دارد یا نه.
 */
class OtpLoginController extends Controller
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

        $throttleKey = 'otp-login:'.$phone.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'sent' => false,
                'message' => 'درخواست بیش از حد؛ چند دقیقهٔ دیگر دوباره تلاش کنید.',
            ], 429);
        }

        RateLimiter::hit($throttleKey, 60);

        $result = app(OtpService::class)->request($phone, 'login');

        return response()->json($result, $result['sent'] ? 200 : 502);
    }

    public function verify(Request $request, RecordAuditLog $recordAuditLog): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ], [
            'code.required' => 'کد تأیید را وارد کنید.',
            'code.digits' => 'کد تأیید باید ۶ رقم باشد.',
        ]);

        $phone = OtpService::normalizePhone((string) $validated['phone']);

        if (! app(OtpService::class)->verify($phone, (string) $validated['code'], 'login')) {
            return response()->json([
                'success' => false,
                'message' => 'کد صحیح نیست یا منقضی شده است.',
            ], 422);
        }

        $user = User::query()->where('phone', $phone)->first();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'حسابی با این شماره یافت نشد. ابتدا ثبت‌نام کنید.',
            ], 404);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $recordAuditLog->handle(
            action: 'auth.login_otp_succeeded',
            subject: $user,
            metadata: ['method' => 'otp'],
        );

        return response()->json([
            'success' => true,
            'message' => 'ورود موفق. در حال انتقال…',
        ]);
    }
}
