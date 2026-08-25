<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Identity\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ورود با کد یکبارمصرف پیامکی.
 *
 * جریان: درخواست کد برای شمارهٔ ثبت‌شده → تأیید کد → ورود.
 * در حالت sandbox (بدون کلید کاوه‌نگار) کد در پاسخ برمی‌گردد تا توسعه
 * و تست بدون پنل پیامکی ممکن باشد (رفتار OtpService).
 */
class OtpLoginController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
    ) {}

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^0?9[0-9]{9}$/'],
        ], [
            'phone.required' => 'شماره تماس را وارد کنید.',
            'phone.regex' => 'شماره تماس معتبر نیست (مثال: 09123456789).',
        ]);

        $phone = OtpService::normalizePhone((string) $data['phone']);

        $user = User::query()->where('phone', $phone)->first();

        // برای جلوگیری از افشای موجود بودن شماره‌ها، پیام یکسان برمی‌گردد؛
        // اما در نبود کاربر، کدی ارسال/برگردانده نمی‌شود.
        if ($user === null) {
            return response()->json([
                'sent' => true,
                'message' => 'اگر این شماره در سیستم ثبت شده باشد، کد ورود ارسال شد.',
            ]);
        }

        $result = $this->otp->request($phone, 'login');

        $payload = ['sent' => $result['sent'], 'message' => $result['message']];

        // فقط در sandbox (بدون کلید واقعی) کد برای توسعه برمی‌گردد.
        if ($result['sent'] && isset($result['code'])) {
            $payload['code'] = $result['code'];
        }

        return response()->json($payload);
    }

    public function verify(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^0?9[0-9]{9}$/'],
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'کد تأیید را وارد کنید.',
            'code.digits' => 'کد تأیید باید ۶ رقم باشد.',
        ]);

        $phone = OtpService::normalizePhone((string) $data['phone']);
        $code = (string) $data['code'];

        $user = User::query()->where('phone', $phone)->first();

        if ($user === null || ! $this->otp->verify($phone, $code, 'login')) {
            return response()->json([
                'success' => false,
                'message' => 'کد وارد شده صحیح نیست یا منقضی شده است.',
            ], 422);
        }

        Auth::login($user);
        $request->session()->regenerate();

        app(RecordAuditLog::class)->handle(
            action: 'auth.login_otp',
            subject: $user,
            metadata: ['phone' => $phone],
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'ورود انجام شد.',
                'location' => route('app.dashboard'),
            ]);
        }

        return redirect()->intended(route('app.dashboard'));
    }
}
