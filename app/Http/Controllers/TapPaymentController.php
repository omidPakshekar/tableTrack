<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Order;
use App\Models\TapPayment;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Events\SendNewOrderReceived;
use App\Events\SendOrderBillEvent;
use App\Services\ShopCartKotPrintUrls;

class TapPaymentController extends Controller
{
    /**
     * Success redirect after payment
     */
    public function success(Request $request)
    {
        info('Tap Success Callback:', $request->all());

        $tapId = $request->query('tap_id') ?? $request->input('tap_id');
        $chargeId = $request->query('charge_id') ?? $request->input('charge_id');
        $orderId = $request->query('order_id') ?? $request->input('order_id');

        // Try to find payment by tap_id (charge ID)
        $tapPayment = null;
        if ($tapId) {
            // Extract charge ID from tap_id if it's in format "chg_xxx"
            $chargeIdFromTapId = $tapId;
            $tapPayment = TapPayment::where('tap_payment_id', $chargeIdFromTapId)->first();
        }
        // Try by charge_id
        if (!$tapPayment && $chargeId) {
            $tapPayment = TapPayment::where('tap_payment_id', $chargeId)->first();
        }
        // Try by order_id (get most recent pending payment)
        if (!$tapPayment && $orderId) {
            $tapPayment = TapPayment::where('order_id', $orderId)
                ->where('payment_status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();
        }
        // Fallback: use session order_id
        if (!$tapPayment && session('tap_order_id')) {
            $tapPayment = TapPayment::where('order_id', session('tap_order_id'))
                ->where('payment_status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (!$tapPayment) {
            Log::warning('Tap Success: Payment not found', [
                'request_data' => $request->all(),
                'session_order_id' => session('tap_order_id'),
            ]);
            session()->flash('flash.banner', 'Payment not found. Please check your order status.');
            session()->flash('flash.bannerStyle', 'warning');
            return redirect()->route('home');
        }

        // Check if payment is already completed (avoid duplicate processing)
        $wasAlreadyCompleted = $tapPayment->payment_status === 'completed';

        // Update payment status only if not already completed
        if (!$wasAlreadyCompleted) {
            $tapPayment->payment_status = 'completed';
            $tapPayment->payment_date = now();
            // Store charge ID from Tap
            if ($tapId) {
                $tapPayment->tap_payment_id = $tapId;
            } elseif ($chargeId) {
                $tapPayment->tap_payment_id = $chargeId;
            }
            $tapPayment->save();
        }

        $order = Order::find($tapPayment->order_id);
        if (!$order) {
            Log::error('Tap Success: Order not found for payment', ['payment_id' => $tapPayment->id]);
            session()->flash('flash.banner', 'Order not found.');
            session()->flash('flash.bannerStyle', 'danger');
            // Clear session
            session()->forget(['tap_order_id']);
            return redirect()->route('home');
        }

        // Only update order if payment wasn't already completed
        if (!$wasAlreadyCompleted) {
            // Update order payment
            $order->amount_paid = ($order->amount_paid ?? 0) + $tapPayment->amount;
            $order->status = 'paid';
            $order->save();

            ShopCartKotPrintUrls::flashDeferredKotPrintForShopOrder($order);

            // Create or update payment record
            Payment::updateOrCreate(
                [
                    'order_id' => $tapPayment->order_id,
                    'payment_method' => 'tap',
                ],
                [
                    'branch_id' => $order->branch_id,
                    'amount' => $tapPayment->amount,
                    'transaction_id' => $tapPayment->tap_payment_id ?? (string)$tapPayment->id,
                ]
            );

            SendNewOrderReceived::dispatch($order);

            if ($order->customer_id) {
                SendOrderBillEvent::dispatch($order);
            }
        }

        // Clear session after successful processing
        session()->forget(['tap_order_id']);

        // Check if order was placed via kiosk and redirect accordingly
        if ($order->placed_via === 'kiosk') {
            return redirect()->route('kiosk.order-confirmation', $order->uuid)->with([
                'flash.banner' => __('messages.paymentDoneSuccessfully'),
                'flash.bannerStyle' => 'success',
            ]);
        }

        return redirect()->route('order_success', $order->uuid)->with([
            'flash.banner' => __('messages.paymentDoneSuccessfully'),
            'flash.bannerStyle' => 'success',
        ]);
    }

    /**
     * Cancel redirect
     */
    public function cancel(Request $request)
    {
        info('Tap Cancel Callback:', $request->all());

        $tapId = $request->query('tap_id') ?? $request->input('tap_id');
        $chargeId = $request->query('charge_id') ?? $request->input('charge_id');
        $orderId = $request->query('order_id') ?? $request->input('order_id');

        // Try to find payment
        $tapPayment = null;
        if ($tapId) {
            $chargeIdFromTapId = $tapId;
            $tapPayment = TapPayment::where('tap_payment_id', $chargeIdFromTapId)->first();
        }
        if (!$tapPayment && $chargeId) {
            $tapPayment = TapPayment::where('tap_payment_id', $chargeId)->first();
        }
        if (!$tapPayment && $orderId) {
            $tapPayment = TapPayment::where('order_id', $orderId)
                ->where('payment_status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();
        }
        if (!$tapPayment && session('tap_order_id')) {
            $tapPayment = TapPayment::where('order_id', session('tap_order_id'))
                ->where('payment_status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($tapPayment) {
            // Only update status if not already completed (don't override success)
            if ($tapPayment->payment_status !== 'completed') {
                $tapPayment->payment_status = 'failed';
                // Store error response for debugging
                $tapPayment->payment_error_response = $request->all();
                $tapPayment->save();
            }

            // Clear session
            session()->forget(['tap_order_id']);

            session()->flash('flash.banner', 'Payment was cancelled.');
            session()->flash('flash.bannerStyle', 'warning');

            // Check if order was placed via kiosk and redirect accordingly
            if ($tapPayment->order && $tapPayment->order->placed_via === 'kiosk') {
                return redirect()->route('kiosk.order-confirmation', $tapPayment->order->uuid);
            }

            return redirect()->route('order_success', $tapPayment->order->uuid);
        }

        // Clear session even if payment not found
        session()->forget(['tap_order_id']);

        session()->flash('flash.banner', 'Payment was cancelled.');
        session()->flash('flash.bannerStyle', 'warning');

        return redirect()->route('home');
    }

    /**
     * Handle webhook from Tap
     * Validates hashstring and processes payment status
     */
    public function webhook(Request $request, $hash)
    {
        info('Tap Webhook Received:', $request->all());

        $restaurant = Restaurant::where('hash', $hash)->first();
        if (!$restaurant) {
            Log::error('Tap Webhook: Restaurant not found for hash: ' . $hash);
            return response()->json(['error' => 'Restaurant not found'], 404);
        }

        // Get payment gateway credentials
        $paymentGateway = $restaurant->paymentGateways;
        if (!$paymentGateway || !$paymentGateway->tap_status) {
            Log::error('Tap Webhook: Tap payment not enabled for restaurant: ' . $hash);
            return response()->json(['error' => 'Tap payment not enabled'], 400);
        }

        // Get webhook data (Tap sends JSON in request body)
        // Laravel automatically parses JSON when Content-Type is application/json
        $webhookData = $request->all();
        
        // If request is JSON, ensure we get the parsed data
        if ($request->isJson()) {
            $webhookData = $request->json()->all();
        }
        
        // Extract charge data (Tap sends charge object in webhook)
        $chargeId = $webhookData['id'] ?? null;
        $status = $webhookData['status'] ?? null;
        $amount = $webhookData['amount'] ?? null;
        $currency = $webhookData['currency'] ?? null;
        $gatewayReference = $webhookData['reference']['gateway'] ?? '';
        $paymentReference = $webhookData['reference']['payment'] ?? null;
        $created = $webhookData['transaction']['created'] ?? null;

        if (!$chargeId) {
            Log::error('Tap Webhook: Missing charge ID', $webhookData);
            return response()->json(['error' => 'Missing charge ID'], 400);
        }

        // Find payment by charge ID
        $tapPayment = TapPayment::where('tap_payment_id', $chargeId)->first();

        if (!$tapPayment) {
            Log::error('Tap Webhook: Payment not found for charge ID: ' . $chargeId);
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $order = $tapPayment->order;
        if (!$order) {
            Log::error('Tap Webhook: Order not found for payment: ' . $tapPayment->id);
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Verify restaurant matches
        if ($order->branch->restaurant_id !== $restaurant->id) {
            Log::error('Tap Webhook: Restaurant mismatch');
            return response()->json(['error' => 'Restaurant mismatch'], 403);
        }

        // Validate hashstring from headers
        $postedHashString = $request->header('hashstring');
        if ($postedHashString) {
            $secretKey = $paymentGateway->tap_mode === 'sandbox' 
                ? $paymentGateway->test_tap_secret_key 
                : $paymentGateway->live_tap_secret_key;

            if ($secretKey) {
                // Calculate hashstring according to Tap documentation
                // Format: x_id{id}x_amount{amount}x_currency{currency}x_gateway_reference{gateway_reference}x_payment_reference{payment_reference}x_status{status}x_created{created}
                $amountRounded = number_format((float)$amount, 2, '.', ''); // Round to 2 decimal places
                $toBeHashedString = 'x_id' . $chargeId 
                    . 'x_amount' . $amountRounded 
                    . 'x_currency' . $currency 
                    . 'x_gateway_reference' . ($gatewayReference ?? '') 
                    . 'x_payment_reference' . ($paymentReference ?? '') 
                    . 'x_status' . $status 
                    . 'x_created' . $created;

                $myHashString = hash_hmac('sha256', $toBeHashedString, $secretKey);

                if ($myHashString !== $postedHashString) {
                    Log::error('Tap Webhook: Hashstring validation failed', [
                        'expected' => $myHashString,
                        'received' => $postedHashString,
                    ]);
                    return response()->json(['error' => 'Invalid hashstring'], 403);
                }
            }
        }

        // Process payment based on status
        if ($status === 'CAPTURED') {
            // Payment successful
            if ($tapPayment->payment_status !== 'completed') {
                $tapPayment->payment_status = 'completed';
                $tapPayment->payment_date = now();
                $tapPayment->payment_error_response = $webhookData;
                $tapPayment->save();

                // Update order
                $order->amount_paid = ($order->amount_paid ?? 0) + $tapPayment->amount;
                $order->status = 'paid';
                $order->save();

                // Create payment record
                Payment::updateOrCreate(
                    [
                        'order_id' => $tapPayment->order_id,
                        'payment_method' => 'tap',
                    ],
                    [
                        'branch_id' => $order->branch_id,
                        'amount' => $tapPayment->amount,
                        'transaction_id' => $tapPayment->tap_payment_id ?? (string)$tapPayment->id,
                    ]
                );

                SendNewOrderReceived::dispatch($order);

                if ($order->customer_id) {
                    SendOrderBillEvent::dispatch($order);
                }
            }

            return response()->json(['status' => 'success', 'message' => 'Payment processed']);
        } elseif ($status === 'FAILED' || $status === 'ABANDONED' || $status === 'CANCELLED') {
            // Payment failed
            if ($tapPayment->payment_status !== 'completed') {
                $tapPayment->payment_status = 'failed';
                $tapPayment->payment_error_response = $webhookData;
                $tapPayment->save();
            }

            Log::warning('Tap Webhook: Payment failed', [
                'charge_id' => $chargeId,
                'status' => $status,
            ]);

            return response()->json(['status' => 'failed', 'message' => 'Payment failed']);
        }

        // Unknown status
        Log::warning('Tap Webhook: Unknown payment status', [
            'charge_id' => $chargeId,
            'status' => $status,
        ]);

        return response()->json(['status' => 'unknown', 'message' => 'Unknown payment status']);
    }
}
