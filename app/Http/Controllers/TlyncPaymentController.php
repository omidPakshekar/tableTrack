<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Order;
use App\Models\TlyncPayment;
use App\Models\Restaurant;
use App\Models\PaymentGatewayCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Events\SendNewOrderReceived;
use App\Events\SendOrderBillEvent;
use App\Services\ShopCartKotPrintUrls;
use App\Services\TlyncPaymentService;

class TlyncPaymentController extends Controller
{
    public function __construct(private TlyncPaymentService $tlync) {}

    private function findTlyncPayment(Request $request): ?TlyncPayment
    {
        $paymentId = $request->query('payment_id') ?? $request->input('payment_id');
        $customRef = $request->query('custom_ref') ?? $request->input('custom_ref');
        $orderId = $request->query('order_id') ?? $request->input('order_id');

        $tlyncPayment = null;

        if ($paymentId) {
            $tlyncPayment = TlyncPayment::find($paymentId);
        }

        if (!$tlyncPayment && $customRef) {
            $tlyncPayment = TlyncPayment::where('custom_ref', $customRef)->first();
        }

        if (!$tlyncPayment && $customRef && str_contains($customRef, '|')) {
            $parts = explode('|', $customRef);
            if (!empty($parts[0]) && is_numeric($parts[0])) {
                $tlyncPayment = TlyncPayment::find((int) $parts[0]);
            }
        }

        if (!$tlyncPayment && $orderId) {
            $tlyncPayment = TlyncPayment::where('order_id', $orderId)
                ->where('payment_status', 'pending')
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$tlyncPayment && session('tlync_payment_id')) {
            $tlyncPayment = TlyncPayment::find(session('tlync_payment_id'));
        }

        if (!$tlyncPayment && session('tlync_order_id')) {
            $tlyncPayment = TlyncPayment::where('order_id', session('tlync_order_id'))
                ->orderByDesc('created_at')
                ->first();
        }

        return $tlyncPayment;
    }

    private function gatewayForPayment(TlyncPayment $tlyncPayment): ?PaymentGatewayCredential
    {
        $order = $tlyncPayment->order;
        if (!$order?->branch) {
            return null;
        }

        return $order->branch->restaurant?->paymentGateways;
    }

    private function gatewayCredentials(PaymentGatewayCredential $gateway): array
    {
        $mode = ($gateway->tlync_mode ?? 'test') === 'live' ? 'live' : 'test';

        return [
            'mode' => $mode,
            'store_id' => $mode === 'live' ? $gateway->live_tlync_store_id : $gateway->test_tlync_store_id,
            'store_token' => $mode === 'live' ? $gateway->live_tlync_store_token : $gateway->test_tlync_store_token,
        ];
    }

    private function tryConfirmViaReceipt(TlyncPayment $tlyncPayment, int $attempts = 5): bool
    {
        if ($tlyncPayment->payment_status === 'completed' || !$tlyncPayment->custom_ref) {
            return $tlyncPayment->payment_status === 'completed';
        }

        $gateway = $this->gatewayForPayment($tlyncPayment);
        if (!$gateway || !$gateway->tlync_status) {
            return false;
        }

        $creds = $this->gatewayCredentials($gateway);
        if (!$creds['store_id'] || !$creds['store_token']) {
            return false;
        }

        $receiptData = $this->tlync->confirmWithReceiptRetries(
            $creds['store_id'],
            $creds['store_token'],
            $creds['mode'],
            $tlyncPayment->custom_ref,
            $attempts
        );

        if ($receiptData) {
            $this->markPaymentCompleted(
                $tlyncPayment,
                $receiptData,
                $this->tlync->transactionIdFromCallback($receiptData)
            );

            return true;
        }

        return false;
    }

    private function markPaymentCompleted(TlyncPayment $tlyncPayment, array $callbackData = [], ?string $transactionId = null): void
    {
        if ($tlyncPayment->payment_status === 'completed') {
            return;
        }

        $tlyncPayment->payment_status = 'completed';
        $tlyncPayment->payment_date = now();
        $tlyncPayment->tlync_payment_id = $transactionId
            ?? $this->tlync->transactionIdFromCallback($callbackData)
            ?? $tlyncPayment->tlync_payment_id;
        $tlyncPayment->payment_error_response = $callbackData ?: $tlyncPayment->payment_error_response;
        $tlyncPayment->save();

        $order = Order::find($tlyncPayment->order_id);
        if (!$order) {
            return;
        }

        $order->amount_paid = ($order->amount_paid ?? 0) + $tlyncPayment->amount;
        $order->status = 'paid';
        $order->save();

        ShopCartKotPrintUrls::flashDeferredKotPrintForShopOrder($order);

        Payment::updateOrCreate(
            [
                'order_id' => $tlyncPayment->order_id,
                'payment_method' => 'tlync',
            ],
            [
                'branch_id' => $order->branch_id,
                'amount' => $tlyncPayment->amount,
                'transaction_id' => $tlyncPayment->tlync_payment_id ?? (string) $tlyncPayment->id,
            ]
        );

        SendNewOrderReceived::dispatch($order);

        if ($order->customer_id) {
            SendOrderBillEvent::dispatch($order);
        }
    }

    private function redirectAfterPayment(Order $order, bool $completed, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $targetUrl = $order->placed_via === 'kiosk'
            ? route('kiosk.order-confirmation', $order->uuid)
            : route('order_success', $order->uuid);

        if ($completed) {
            session()->flash('flash.banner', __('messages.paymentDoneSuccessfully'));
            session()->flash('flash.bannerStyle', 'success');
        } else {
            session()->flash('flash.banner', __('messages.tlyncPaymentPending'));
            session()->flash('flash.bannerStyle', 'warning');
        }

        // T-Lync POSTs a form to frontend_url — return HTML so the browser navigates reliably.
        if ($request->isMethod('POST')) {
            return response(
                '<!DOCTYPE html><html><head>'
                . '<meta charset="utf-8">'
                . '<meta http-equiv="refresh" content="0;url=' . e($targetUrl) . '">'
                . '</head><body>'
                . '<script>window.location.replace(' . json_encode($targetUrl) . ');</script>'
                . '<p>Redirecting...</p>'
                . '</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }

        return redirect()->to($targetUrl);
    }

    /**
     * Browser return URL after T-Lync checkout (POST/GET) — mirrors Tap success flow.
     */
    public function success(Request $request)
    {
        Log::info('T-Lync Success Callback', [
            'method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'payload' => $request->all(),
        ]);

        // T-Lync may GET the frontend_url before POSTing the payment result (no session).
        // When the customer returns after checkout, session still holds the pending payment.
        if ($request->isMethod('GET') && $request->all() === [] && ! session('tlync_payment_id') && ! session('tlync_order_id')) {
            return response('OK', 200);
        }

        $tlyncPayment = $this->findTlyncPayment($request);

        if (!$tlyncPayment) {
            Log::warning('T-Lync Success: Payment not found', [
                'request_data' => $request->all(),
                'session_payment_id' => session('tlync_payment_id'),
                'session_order_id' => session('tlync_order_id'),
            ]);
            session()->flash('flash.banner', 'Payment not found. Please check your order status.');
            session()->flash('flash.bannerStyle', 'warning');
            return redirect()->route('home');
        }

        $wasAlreadyCompleted = $tlyncPayment->payment_status === 'completed';

        if (!$wasAlreadyCompleted) {
            if ($this->tlync->isApprovedCallback($request->all())) {
                $this->markPaymentCompleted(
                    $tlyncPayment,
                    $request->all(),
                    $this->tlync->transactionIdFromCallback($request->all())
                );
            } else {
                $this->tryConfirmViaReceipt($tlyncPayment);
            }
        }

        $tlyncPayment->refresh();

        session()->forget(['tlync_payment_id', 'tlync_order_id']);

        $order = Order::find($tlyncPayment->order_id);
        if (!$order) {
            Log::error('T-Lync Success: Order not found', ['payment_id' => $tlyncPayment->id]);
            session()->flash('flash.banner', 'Order not found.');
            session()->flash('flash.bannerStyle', 'danger');
            return redirect()->route('home');
        }

        return $this->redirectAfterPayment($order, $tlyncPayment->payment_status === 'completed', $request);
    }

    public function cancel(Request $request)
    {
        Log::info('T-Lync Cancel Callback', $request->all());

        $tlyncPayment = $this->findTlyncPayment($request);

        if ($tlyncPayment && $tlyncPayment->payment_status !== 'completed') {
            $tlyncPayment->payment_status = 'failed';
            $tlyncPayment->payment_error_response = $request->all();
            $tlyncPayment->save();
        }

        session()->forget(['tlync_payment_id', 'tlync_order_id']);

        session()->flash('flash.banner', 'Payment was cancelled.');
        session()->flash('flash.bannerStyle', 'warning');

        if ($tlyncPayment?->order) {
            if ($tlyncPayment->order->placed_via === 'kiosk') {
                return redirect()->route('kiosk.order-confirmation', $tlyncPayment->order->uuid);
            }

            return redirect()->route('order_success', $tlyncPayment->order->uuid);
        }

        return redirect()->route('home');
    }

    public function webhook(Request $request, string $hash)
    {
        if ($request->isMethod('GET')) {
            return response('OK', 200);
        }

        $data = $request->all();

        Log::info('T-Lync Webhook Received', [
            'hash' => $hash,
            'content_type' => $request->header('Content-Type'),
            'payload' => $data,
        ]);

        $restaurant = Restaurant::where('hash', $hash)->first();
        if (!$restaurant) {
            Log::error('T-Lync Webhook: Restaurant not found for hash: ' . $hash);
            return response()->json(['error' => 'Restaurant not found'], 404);
        }

        $paymentGateway = $restaurant->paymentGateways;
        if (!$paymentGateway || !$paymentGateway->tlync_status) {
            Log::error('T-Lync Webhook: T-Lync payment not enabled for restaurant: ' . $hash);
            return response()->json(['error' => 'T-Lync payment not enabled'], 400);
        }

        $customRef = $data['custom_ref'] ?? null;

        $tlyncPayment = null;
        if ($customRef) {
            $tlyncPayment = TlyncPayment::where('custom_ref', $customRef)->first();

            if (!$tlyncPayment && str_contains($customRef, '|')) {
                $parts = explode('|', $customRef);
                if (!empty($parts[0]) && is_numeric($parts[0])) {
                    $tlyncPayment = TlyncPayment::find((int) $parts[0]);
                }
            }
        }

        if (!$tlyncPayment) {
            Log::error('T-Lync Webhook: Payment not found', $data);
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $order = $tlyncPayment->order;
        if (!$order || $order->branch->restaurant_id !== $restaurant->id) {
            Log::error('T-Lync Webhook: Order/restaurant mismatch');
            return response()->json(['error' => 'Restaurant mismatch'], 403);
        }

        if ($this->tlync->isApprovedCallback($data)) {
            $this->markPaymentCompleted(
                $tlyncPayment,
                $data,
                $this->tlync->transactionIdFromCallback($data)
            );

            Log::info('T-Lync payment marked completed', [
                'payment_id' => $tlyncPayment->id,
                'order_id' => $tlyncPayment->order_id,
                'custom_ref' => $customRef,
            ]);

            return response()->json(['result' => 'success'], 200);
        }

        if ($tlyncPayment->payment_status !== 'completed') {
            $this->tryConfirmViaReceipt($tlyncPayment, 2);
            $tlyncPayment->refresh();
        }

        if ($tlyncPayment->payment_status === 'completed') {
            return response()->json(['result' => 'success'], 200);
        }

        return response()->json(['result' => 'received'], 200);
    }
}
