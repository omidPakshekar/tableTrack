<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\AdminMolliePayment;
use App\Models\Order;
use App\Events\SendNewOrderReceived;
use App\Events\SendOrderBillEvent;
use App\Services\ShopCartKotPrintUrls;
use Mollie\Api\MollieApiClient;

class MolliePaymentController extends Controller
{
    private $apiKey;

    /**
     * Set Mollie API key based on restaurant hash.
     */
    private function setKeys(string $societyHash): void
    {
        $restaurant = Restaurant::where('hash', $societyHash)->firstOrFail();
        $paymentGateway = $restaurant->paymentGateways;
        $isSandbox = $paymentGateway->mollie_mode === 'test';
        $this->apiKey = $isSandbox ? $paymentGateway->test_mollie_key : $paymentGateway->live_mollie_key;
    }

    /**
     * Handle Mollie webhook notifications.
     */
    public function handleGatewayWebhook(Request $request, string $hash)
    {
        Log::info('Mollie webhook hit', $request->all());

        try {
            $this->setKeys($hash);

            $paymentId = $request->input('id');

            if (!$paymentId) {
                return response()->json(['message' => 'Invalid webhook payload'], 400);
            }

            // Initialize Mollie API client
            $mollie = new MollieApiClient();
            $mollie->setApiKey($this->apiKey);

            // Retrieve payment status from Mollie
            $payment = $mollie->payments->get($paymentId);

            $molliePayment = AdminMolliePayment::where('mollie_payment_id', $paymentId)->first();

            if (!$molliePayment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }

            if ($payment->isPaid() && !$payment->hasRefunds() && !$payment->hasChargebacks()) {
                $this->markPaymentAsCompleted($molliePayment);
                return response()->json(['message' => 'Payment successful']);
            }

            if ($payment->isFailed()) {
                $molliePayment->update([
                    'payment_status' => 'failed',
                    'payment_error_response' => json_encode([
                    'status' => $payment->status,
                    'failureReason' => $payment->details->failureReason ?? null,
                    ]),
                ]);
                return response()->json(['message' => 'Payment failed event processed']);
            }

            if ($payment->isCanceled()) {
                $molliePayment->update([
                    'payment_status' => 'failed',
                    'payment_error_response' => json_encode(['status' => 'canceled']),
                ]);
                return response()->json(['message' => 'Payment canceled event processed']);
            }

            return response()->json(['message' => 'Event not handled'], 400);
        } catch (\Exception $e) {
            Log::error('Mollie webhook error: ' . $e->getMessage());
            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Handle redirect after failed payment.
     */
    public function paymentFailed(Request $request)
    {
        $orderId = $request->order_id;
        $payment = null;

        if ($orderId) {
            $payment = AdminMolliePayment::where('order_id', $orderId)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($payment) {
                $payment->update([
                    'payment_status' => 'failed',
                    'payment_error_response' => json_encode(['message' => 'Payment was canceled or failed']),
                ]);
            }
        }

        session()->flash('flash.banner', 'Payment process failed!');
        session()->flash('flash.bannerStyle', 'danger');

        if ($payment && $payment->order) {
            return redirect(route('shop_restaurant', [$payment->order->branch->restaurant->hash]) . '?branch=' . $payment->order->branch_id);
        }

        return redirect()->back();
    }

    /**
     * Handle redirect after successful payment.
     */
    public function paymentMainSuccess(Request $request)
    {
        $orderId = $request->order_id;

        if (!$orderId) {
            return $this->redirectWithMessage('No order ID supplied!', 'danger');
        }

        $payment = AdminMolliePayment::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$payment) {
            return $this->redirectWithMessage('Payment not found!', 'danger');
        }

        try {
            $order = $payment->order;
            $restaurant = $payment->order->branch->restaurant;
            $paymentGateway = $restaurant->paymentGateways;
            $isSandbox = $paymentGateway->mollie_mode === 'test';
            $apiKey = $isSandbox ? $paymentGateway->test_mollie_key : $paymentGateway->live_mollie_key;

            // Initialize Mollie API client
            $mollie = new MollieApiClient();
            $mollie->setApiKey($apiKey);

            // Retrieve payment status from Mollie
            $molliePayment = $mollie->payments->get($payment->mollie_payment_id);

            if ($molliePayment->isPaid() && !$molliePayment->hasRefunds() && !$molliePayment->hasChargebacks()) {
                $this->markPaymentAsCompleted($payment, true);
                return $this->redirectWithMessage('Payment processed successfully!', 'success', $order->uuid);
            }

            // Failed fallback
            $payment->update([
                'payment_status' => 'failed',
                'payment_error_response' => json_encode(['status' => $molliePayment->status]),
            ]);

            return $this->redirectWithMessage('Payment process failed!', 'danger', $order->uuid);
        } catch (\Exception $e) {
            Log::error('Mollie success callback error: ' . $e->getMessage());
            return $this->redirectWithMessage('Error verifying payment: ' . $e->getMessage(), 'danger', $order->uuid);
        }
    }

    /**
     * Mark payment and order as completed, and create a Payment record.
     */
    private function markPaymentAsCompleted(AdminMolliePayment $payment, bool $createPayment = false): void
    {
        if ($createPayment) {
            $order = Order::find($payment->order_id);

            Payment::updateOrCreate(
                [
                    'order_id' => $payment->order_id,
                    'payment_method' => 'due',
                    'amount' => $payment->amount,
                ],
                [
                    'payment_method' => 'mollie',
                    'branch_id' => $order->branch_id ?? null,
                    'transaction_id' => $payment->mollie_payment_id,
                ]
            );
        }

        $order = Order::find($payment->order_id);
        $order->amount_paid = $order->amount_paid + $payment->amount;
        $order->status = 'paid';
        $order->save();

        ShopCartKotPrintUrls::flashDeferredKotPrintForShopOrder($order);

        $payment->update([
            'payment_status' => 'completed',
            'payment_date' => now(),
        ]);

        SendNewOrderReceived::dispatch($order);

        if ($order->customer_id) {
            SendOrderBillEvent::dispatch($order);
        }
    }

    /**
     * Utility for flashing message and redirecting.
     */
    private function redirectWithMessage(string $message, string $type, ?string $orderUuid = null)
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', $type);

        if ($orderUuid) {
            $order = Order::where('uuid', $orderUuid)->first();
            if ($order && $order->placed_via === 'kiosk') {
                return redirect()->route('kiosk.order-confirmation', $order->uuid);
            }

            return redirect()->route('order_success', $orderUuid);
        }

        return redirect()->back();
    }
}

