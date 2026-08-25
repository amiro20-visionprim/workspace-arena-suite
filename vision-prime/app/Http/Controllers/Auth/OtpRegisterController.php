<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Services\OtpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ارسال کد یکبارمصرف برای تأیید شماره تماس در ثبت‌نام.
 *
 * شمارهٔ تکراری رد می‌شود (422)؛ برای شمارهٔ آزاد کد صادر و در sandbox
 * در پاسخ برمی‌گردد تا جریان بدون پنل پیامکی قابل تست باشد.
 */
class OtpRegisterController extends Controller
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

        $taken = DB::table('users')->where('phone', $phone)->exists();

        if ($taken) {
            return response()->json([
                'sent' => false,
                'message' => 'این شماره تماس قبلاً ثبت شده است؛ از ورود با کد یکبارمصرف استفاده کنید.',
            ], 422);
        }

        $result = $this->otp->request($phone, 'register');

        $payload = ['sent' => $result['sent'], 'message' => $result['message']];

        // فقط در sandbox (بدون کلید واقعی) کد برای توسعه برمی‌گردد.
        if ($result['sent'] && isset($result['code'])) {
            $payload['code'] = $result['code'];
        }

        return response()->json($payload);
    }
}
