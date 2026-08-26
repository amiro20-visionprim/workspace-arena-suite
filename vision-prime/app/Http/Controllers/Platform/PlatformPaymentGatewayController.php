<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Platform\Models\Payment;
use App\Domains\Platform\Services\PaymentGatewayManager;
use App\Domains\Platform\Services\PaymentService;
use App\Domains\Platform\Services\PlatformSettingsService;
use App\Domains\Platform\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlatformPaymentGatewayController
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly SubscriptionService $subscriptions,
        private readonly RecordAuditLog $audit,
        private readonly PlatformSettingsService $settings,
    ) {}

    /**
     * شروع پرداخت آنلاین برای یک payment موجود (pending).
     */
    public function pay(Request $request, Payment $payment, string $gateway): RedirectResponse
    {
        // Feature-flag: پرداخت آنلاین تا تأیید کامل جریان سندباکس→پروd بسته است.
        // روشن‌کردن: PlatformSettingsService::set('payments_enabled', true)
        if (! $this->settings->bool('payments_enabled', false)) {
            return back()->with('error', 'پرداخت آنلاین موقتاً غیرفعال است؛ با پشتیبانی هماهنگ کنید.');
        }

        abort_if($payment->status !== Payment::STATUS_PENDING, 422, 'این پرداخت قابل ادامه نیست.');

        $driver = $this->gateways->get($gateway);
        $callback = route('platform.payments.callback', [
            'gateway' => $gateway,
            'transaction' => $payment->gateway_transaction_id ?: $payment->reference,
        ], absolute: true);

        try {
            $result = $driver->initiate($payment, $callback);
        } catch (\Throwable $e) {
            $this->audit->handle(
                action: 'platform.payment.initiate_failed',
                subject: $payment,
                metadata: ['gateway' => $gateway, 'error' => $e->getMessage()],
            );

            return back()->with('error', "درگاه «{$driver->label()}» در دسترس نیست: {$e->getMessage()}");
        }

        $this->audit->handle(
            action: 'platform.payment.initiated',
            subject: $payment,
            after: ['gateway' => $gateway, 'transaction_id' => $result['transaction_id']],
        );

        return redirect()->away($result['redirect_url']);
    }

    /**
     * بازگشت کاربر از درگاه — verify + علامت‌گذاری پرداخت.
     */
    public function callback(Request $request, string $gateway, string $transaction): RedirectResponse|View
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where(fn ($q) => $q->where('gateway_transaction_id', $transaction)->orWhere('reference', $transaction))
            ->first();

        if ($payment === null) {
            abort(404, 'پرداخت یافت نشد.');
        }

        if ($payment->status === Payment::STATUS_PAID) {
            return $this->finish($payment, true);
        }

        $driver = $this->gateways->get($gateway);

        try {
            $ok = DB::transaction(fn () => $driver->verify($payment, $request->all()));
        } catch (\Throwable $e) {
            $this->audit->handle(
                action: 'platform.payment.verify_failed',
                subject: $payment,
                metadata: ['gateway' => $gateway, 'error' => $e->getMessage()],
            );
            $ok = false;
        }

        if ($ok) {
            $this->payments->markPaid($payment);

            // پول آمد → سرویس هم باید فعال شود: تمدید/فعال‌سازی اشتراک همین لحظه.
            // (پیش از این پرداخت فقط paid می‌شد و اشتراک trialing می‌ماند!)
            if ($payment->subscription_id !== null) {
                $this->subscriptions->renew($payment->subscription()->firstOrFail());
            }

            $this->audit->handle(
                action: 'platform.payment.verified',
                subject: $payment,
                after: ['gateway' => $gateway, 'status' => $payment->status],
            );
        } else {
            $this->payments->markFailed($payment);
        }

        return $this->finish($payment, $ok);
    }

    private function finish(Payment $payment, bool $ok): RedirectResponse|View
    {
        return view('platform.payment-result', [
            'ok' => $ok,
            'amount' => $payment->amount,
            'reference' => $payment->reference,
            'gateway' => $payment->gateway_transaction_id,
        ]);
    }
}
