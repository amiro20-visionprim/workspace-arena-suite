<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // اگر در پروداکشن ارسال ایمیل واقعی پیکربندی نشده (MAIL_MAILER=log)، به‌جای
        // «موفقیت کاذب»، پیام صادقانه بدهیم تا کاربر در انتظار ایمیلی که هرگز نمی‌آید نماند.
        // (در محیط توسعه/تست جریان عادی حفظ می‌شود تا تست‌ها و توسعه بدون SMTP ممکن باشد.)
        if (app()->environment('production') && config('mail.default') === 'log') {
            return back()->withErrors([
                'email' => 'سامانهٔ ارسال ایمیل هنوز پیکربندی نشده است. لطفاً از طریق پشتیبانی، بازیابی رمز را درخواست کنید.',
            ]);
        }

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', 'اگر حسابی با این ایمیل وجود داشته باشد، لینک بازیابی رمز عبور ارسال می‌شود.')
            : back()->withErrors(['email' => 'ارسال لینک بازیابی در حال حاضر ممکن نیست. لطفاً دوباره تلاش کنید.']);
    }
}
