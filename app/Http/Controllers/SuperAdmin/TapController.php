<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\User;
use App\Models\Package;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use App\Models\GlobalInvoice;
use App\Models\RestaurantPayment;
use App\Models\GlobalSubscription;
use App\Http\Controllers\Controller;
use App\Models\SuperadminPaymentGateway;
use App\Notifications\RestaurantUpdatedPlan;
use App\Models\EmailSetting;
use App\Enums\PackageType;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class TapController extends Controller
{
    /**
     * Load Tap API credentials from DB
     */
    private function getTapCredentials(): array
    {
        $gateway = SuperadminPaymentGateway::first();

        if (!$gateway || !($gateway->tap_status ?? false)) {
            throw new \Exception("Tap payment gateway is not configured");
        }

        $isSandbox = ($gateway->tap_mode ?? 'sandbox') === 'sandbox';

        return [
            'secret_key' => $isSandbox ? $gateway->test_tap_secret_key : $gateway->live_tap_secret_key,
            'public_key' => $isSandbox ? $gateway->test_tap_public_key : $gateway->live_tap_public_key,
            'merchant_id' => $gateway->tap_merchant_id,
            'is_sandbox' => $isSandbox,
        ];
    }

    /**
     * Initiate payment - routes to lifetime or subscription handler
     */
    public function initiatePayment(Request $request)
    {
        try {
            $restaurantPayment = RestaurantPayment::findOrFail($request->payment_id);
            $package = Package::findOrFail($request->input('package_id'));

            return $package->package_type === PackageType::LIFETIME
                ? $this->handleLifetimePayment($restaurantPayment, $package)
                : $this->handleSubscriptionPayment($request, $restaurantPayment, $package);
        } catch (\Exception $e) {
            Log::error('Tap: Payment initiation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Session::put('error', 'Failed to initiate payment: ' . $e->getMessage());
            return redirect()->route('pricing.plan');
        }
    }

    /**
     * Handle lifetime payment using Charge API
     */
    private function handleLifetimePayment($restaurantPayment, $package)
    {
        try {
            $credentials = $this->getTapCredentials();
            $amount = $package->price;
            $currency = strtoupper($package->currency->currency_code);

            if (!$amount || !$currency) {
                Session::put('error', 'Invalid package details');
                return redirect()->route('pricing.plan');
            }

            // Update restaurant payment
            $restaurantPayment->update([
                'amount' => $amount,
                'status' => 'pending',
                'payment_date_time' => now(),
            ]);

            $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
            $email = user()->email ?? $restaurant->email;
            $name = user()->name ?? $restaurant->name ?? 'Guest';

            // Prepare charge data for Tap Charge API
            $chargeData = [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
                'threeDSecure' => true,
                'save_card' => false,
                'description' => "Lifetime Plan Payment - {$package->name}",
                'statement_descriptor' => "Plan #{$package->id}",
                'metadata' => [
                    'udf1' => 'Package ID: ' . $package->id,
                    'udf2' => 'Restaurant: ' . $restaurant->name,
                    'udf3' => 'Payment Type: Lifetime',
                ],
                'reference' => [
                    'transaction' => 'txn_' . $restaurantPayment->id,
                    'order' => 'ord_' . $restaurantPayment->id,
                ],
                'receipt' => [
                    'email' => false,
                    'sms' => false,
                ],
                'customer' => [
                    'first_name' => $name,
                    'email' => $email,
                    'phone' => [
                        'country_code' => '966',
                        'number' => '000000000',
                    ],
                ],
                'merchant' => [
                    'id' => $credentials['merchant_id'],
                ],
                'source' => [
                    'id' => 'src_all',
                ],
                'redirect' => [
                    'url' => route('tap.plan.success', ['payment_id' => $restaurantPayment->id]),
                ],
                'post' => [
                    'url' => route('tap.plan.webhook'),
                ],
            ];

            // Make API call to Tap Charge API
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $credentials['secret_key'],
                'Content-Type' => 'application/json',
            ])->post('https://api.tap.company/v2/charges', $chargeData);

            $responseData = $response->json();
            Log::info('Tap Lifetime Charge Response:', $responseData);

            if ($response->successful() && isset($responseData['id'])) {
                // Update payment record with charge ID
                $restaurantPayment->update([
                    'transaction_id' => $responseData['id'],
                ]);

                // Store payment ID in session
                Session::put('tap_payment_id', $restaurantPayment->id);

                // Get Tap hosted checkout URL
                $checkoutUrl = $responseData['transaction']['url'] ?? null;

                if ($checkoutUrl) {
                    return redirect()->away($checkoutUrl);
                } else {
                    // If no checkout URL, check if payment was already captured
                    if (isset($responseData['status']) && $responseData['status'] === 'CAPTURED') {
                        return redirect()->route('tap.plan.success', [
                            'payment_id' => $restaurantPayment->id,
                            'tap_id' => $responseData['id']
                        ]);
                    } else {
                        Session::put('error', 'Payment initiation failed. Please try again.');
                        return redirect()->route('pricing.plan');
                    }
                }
            } else {
                $errorMessage = $responseData['errors'][0]['message'] ?? 'Payment initiation failed. Please try again.';
                Session::put('error', $errorMessage);
                return redirect()->route('pricing.plan');
            }
        } catch (\Exception $e) {
            Log::error('Tap Lifetime Payment: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Session::put('error', 'Failed to initiate payment: ' . $e->getMessage());
            return redirect()->route('pricing.plan');
        }
    }

    /**
     * Handle subscription payment using Invoice API
     */
    private function handleSubscriptionPayment(Request $request, $restaurantPayment, $package)
    {
        try {
            $credentials = $this->getTapCredentials();
            $planType = $request->input('package_type');
            $amount = $planType === 'annual' ? $package->annual_price : $package->monthly_price;
            $currency = strtoupper($package->currency->currency_code);

            // Validate price and currency
            if (!$amount || !$currency) {
                Session::put('error', 'Invalid package details');
                return redirect()->route('pricing.plan');
            }

            // Calculate due and expiry dates (30 days from now for subscription)
            $dueDate = now()->addDays(30)->timestamp * 1000; // milliseconds
            $expiryDate = now()->addDays(30)->timestamp * 1000; // milliseconds

            $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
            $email = user()->email ?? $restaurant->email;
            $name = user()->name ?? $restaurant->name ?? 'Guest';

            // Prepare invoice data for Tap Invoice API
            $invoiceData = [
                'draft' => false,
                'due' => $dueDate,
                'expiry' => $expiryDate,
                'description' => "Subscription Payment - {$package->name} ({$planType})",
                'mode' => 'INVOICEPAY', // Show both invoice and payment page
                'note' => "Recurring payment for {$package->name}",
                'notifications' => [
                    'channels' => ['SMS', 'EMAIL'],
                    'dispatch' => true,
                ],
                'currencies' => [$currency],
                'metadata' => [
                    'udf1' => 'Package ID: ' . $package->id,
                    'udf2' => 'Restaurant: ' . $restaurant->name,
                    'udf3' => 'Payment Type: Subscription',
                    'udf4' => 'Package Type: ' . $planType,
                ],
                'charge' => [
                    'receipt' => [
                        'email' => true,
                        'sms' => true,
                    ],
                ],
                'customer' => [
                    'first_name' => explode(' ', $name)[0] ?? $name,
                    'last_name' => count(explode(' ', $name)) > 1 ? implode(' ', array_slice(explode(' ', $name), 1)) : '',
                    'email' => $email,
                    'phone' => [
                        'country_code' => '966',
                        'number' => '000000000',
                    ],
                ],
                'statement_descriptor' => "Plan #{$package->id}",
                'order' => [
                    'amount' => number_format($amount, 2, '.', ''),
                    'currency' => $currency,
                    'items' => [
                        [
                            'name' => $package->name,
                            'description' => "{$planType} subscription",
                            'quantity' => 1,
                            'amount' => number_format($amount, 2, '.', ''),
                        ],
                    ],
                ],
                'post' => [
                    'url' => route('tap.plan.webhook'),
                ],
                'redirect' => [
                    'url' => route('tap.plan.success', ['payment_id' => $restaurantPayment->id]),
                ],
                'reference' => [
                    'invoice' => 'INV_' . $restaurantPayment->id,
                    'order' => 'ORD_' . $restaurantPayment->id,
                ],
                'retry_for_captured' => true,
            ];

            // Make API call to Tap Invoice API
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $credentials['secret_key'],
                'Content-Type' => 'application/json',
            ])->post('https://api.tap.company/v2/invoices/', $invoiceData);

            $responseData = $response->json();
            Log::info('Tap Subscription Invoice Response:', $responseData);

            if ($response->successful() && isset($responseData['id'])) {
                // Update restaurant payment with invoice ID
                $restaurantPayment->update([
                    'amount' => $amount,
                    'status' => 'pending',
                    'payment_date_time' => now(),
                    'transaction_id' => $responseData['id'], // Store invoice ID
                ]);

                // Store payment details in session for later use
                Session::put('tap_payment_id', $restaurantPayment->id);
                Session::put('tap_invoice_id', $responseData['id']);
                Session::put('tap_package_id', $package->id);
                Session::put('tap_package_type', $planType);
                Session::put('tap_amount', $amount);
                Session::put('tap_restaurant_id', $restaurant->id);

                // Get invoice URL for redirect
                $invoiceUrl = $responseData['url'] ?? null;

                if ($invoiceUrl) {
                    return redirect()->away($invoiceUrl);
                } else {
                    Session::put('error', 'Payment initiation failed. Please try again.');
                    return redirect()->route('pricing.plan');
                }
            } else {
                $errorMessage = $responseData['errors'][0]['message'] ?? 'Payment initiation failed. Please try again.';
                Session::put('error', $errorMessage);
                return redirect()->route('pricing.plan');
            }
        } catch (\Exception $e) {
            Log::error('Tap Subscription Payment: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Session::put('error', 'Failed to initiate payment: ' . $e->getMessage());
            return redirect()->route('pricing.plan');
        }
    }

    /**
     * Handle plan payment success callback (for both lifetime and subscription)
     */
    public function paymentSuccess(Request $request)
    {
        try {
            $paymentId = $request->input('payment_id') ?? Session::get('tap_payment_id');
            $tapId = $request->query('tap_id') ?? $request->input('tap_id');
            $invoiceId = $request->query('invoice_id') ?? Session::get('tap_invoice_id');

            if (!$paymentId) {
                Log::error('Tap Plan: No payment ID provided in success callback');
                Session::put('error', __('messages.paymentFailed'));
                return redirect()->route('pricing.plan');
            }

            $restaurantPayment = RestaurantPayment::find($paymentId);

            if (!$restaurantPayment) {
                Log::error('Tap Plan: Restaurant payment not found', ['payment_id' => $paymentId]);
                Session::put('error', __('messages.paymentFailed'));
                return redirect()->route('pricing.plan');
            }

            $credentials = $this->getTapCredentials();
            $secretKey = $credentials['secret_key'];

            // Determine if this is a charge (lifetime) or invoice (subscription)
            $isInvoice = !empty($invoiceId) || !empty($restaurantPayment->transaction_id) && strpos($restaurantPayment->transaction_id, 'inv_') === 0;
            $transactionId = $tapId ?? $invoiceId ?? $restaurantPayment->transaction_id;

            if (!$transactionId) {
                Log::error('Tap Plan: No transaction ID found', ['payment_id' => $paymentId]);
                Session::put('error', __('messages.paymentFailed'));
                return redirect()->route('pricing.plan');
            }

            if ($isInvoice) {
                // Handle invoice payment (subscription)
                return $this->handleInvoiceSuccess($restaurantPayment, $transactionId, $secretKey);
            } else {
                // Handle charge payment (lifetime)
                return $this->handleChargeSuccess($restaurantPayment, $transactionId, $secretKey);
            }
        } catch (\Exception $e) {
            Log::error('Tap Plan: Exception during payment processing: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            Session::put('error', __('messages.paymentFailed'));
            return redirect()->route('pricing.plan');
        }
    }

    /**
     * Handle charge success (lifetime payment)
     */
    private function handleChargeSuccess($restaurantPayment, $chargeId, $secretKey)
    {
        $verifyResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $secretKey,
            'Content-Type' => 'application/json',
        ])->get('https://api.tap.company/v2/charges/' . $chargeId);

        $verifyData = $verifyResponse->json();
        Log::info('Tap Plan Charge Verification Response:', $verifyData);

        if ($verifyResponse->successful() && isset($verifyData['status'])) {
            if ($verifyData['status'] === 'CAPTURED' || $verifyData['status'] === 'PAID') {
                // Extract Tap customer and token/card identifiers if available
                $tapCustomerId = $verifyData['customer']['id'] ?? null;
                $tapTokenId = $verifyData['source']['id'] ?? ($verifyData['card']['id'] ?? null);

                return $this->processPaymentSuccess($restaurantPayment, $chargeId, 'lifetime', $tapCustomerId, $tapTokenId);
            } else {
                Log::error('Tap Plan: Payment verification failed or not captured.', [
                    'charge_id' => $chargeId,
                    'status' => $verifyData['status']
                ]);
                Session::put('error', __('messages.paymentFailed'));
                return redirect()->route('pricing.plan');
            }
        } else {
            Log::error('Tap Plan: Failed to verify charge status.', [
                'charge_id' => $chargeId,
                'response' => $verifyData
            ]);
            Session::put('error', __('messages.paymentFailed'));
            return redirect()->route('pricing.plan');
        }
    }

    /**
     * Handle invoice success (subscription payment)
     */
    private function handleInvoiceSuccess($restaurantPayment, $invoiceId, $secretKey)
    {
        $verifyResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $secretKey,
            'Content-Type' => 'application/json',
        ])->get('https://api.tap.company/v2/invoices/' . $invoiceId);

        $verifyData = $verifyResponse->json();
        Log::info('Tap Plan Invoice Verification Response:', $verifyData);

        if ($verifyResponse->successful() && isset($verifyData['status'])) {
            // Check if invoice has been paid (status can be PAID, CAPTURED, etc.)
            $isPaid = isset($verifyData['status']) && in_array($verifyData['status'], ['PAID', 'CAPTURED']);
            
            // Also check charges within the invoice
            $charges = $verifyData['charges'] ?? [];
            $hasPaidCharge = false;
            foreach ($charges as $charge) {
                if (isset($charge['status']) && in_array($charge['status'], ['CAPTURED', 'PAID'])) {
                    $hasPaidCharge = true;
                    break;
                }
            }

            if ($isPaid || $hasPaidCharge) {
                // Get the charge ID from the invoice if available
                $chargeId = null;
                foreach ($charges as $charge) {
                    if (isset($charge['id'])) {
                        $chargeId = $charge['id'];
                        break;
                    }
                }

                // Extract Tap customer and token/card identifiers if available
                $tapCustomerId = $verifyData['customer']['id'] ?? null;
                $tapTokenId = $verifyData['source']['id'] ?? null;
                
                return $this->processPaymentSuccess(
                    $restaurantPayment,
                    $chargeId ?? $invoiceId,
                    $restaurantPayment->package_type,
                    $tapCustomerId,
                    $tapTokenId
                );
            } else {
                Log::error('Tap Plan: Invoice not paid.', [
                    'invoice_id' => $invoiceId,
                    'status' => $verifyData['status']
                ]);
                Session::put('error', __('messages.paymentFailed'));
                return redirect()->route('pricing.plan');
            }
        } else {
            Log::error('Tap Plan: Failed to verify invoice status.', [
                'invoice_id' => $invoiceId,
                'response' => $verifyData
            ]);
            Session::put('error', __('messages.paymentFailed'));
            return redirect()->route('pricing.plan');
        }
    }

    /**
     * Process successful payment and update database
     */
    private function processPaymentSuccess($restaurantPayment, $transactionId, $packageType, $tapCustomerId = null, $tapTokenId = null)
    {
        DB::beginTransaction();

        try {
            $restaurantPayment->status = 'paid';
            $restaurantPayment->payment_date_time = now();
            $restaurantPayment->transaction_id = $transactionId;
            $restaurantPayment->save();

            $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
            $package = Package::find($restaurantPayment->package_id);
            $isRecurring = $packageType !== 'lifetime';

            // Update restaurant package details
            $restaurant->package_id = $restaurantPayment->package_id;
            $restaurant->package_type = $restaurantPayment->package_type;
            $restaurant->trial_ends_at = null;
            $restaurant->is_active = true;
            $restaurant->status = 'active';
            $restaurant->license_expire_on = null;
            $restaurant->license_updated_at = now();
            $restaurant->save();

            // Clear restaurant modules cache
            clearRestaurantModulesCache($restaurant->id);

            // Deactivate existing subscriptions
            GlobalSubscription::where('restaurant_id', $restaurant->id)
                ->where('subscription_status', 'active')
                ->update(['subscription_status' => 'inactive']);

            // Create GlobalSubscription record
            $subscription = GlobalSubscription::create([
                'restaurant_id' => $restaurant->id,
                'package_id' => $package->id,
                'currency_id' => $restaurantPayment->currency_id,
                'package_type' => $restaurantPayment->package_type,
                'transaction_id' => $transactionId,
                'gateway_name' => 'tap',
                'subscription_status' => 'active',
                'subscribed_on_date' => now(),
                'ends_at' => $isRecurring ? ($restaurantPayment->package_type === 'annual' ? now()->addYear() : now()->addMonth()) : null,
                'quantity' => 1,
                // Store Tap subscription identifiers if available
                'customer_id' => $tapCustomerId,
                'token' => $tapTokenId,
            ]);

            // Create GlobalInvoice
            GlobalInvoice::create([
                'restaurant_id' => $restaurant->id,
                'package_id' => $package->id,
                'currency_id' => $restaurantPayment->currency_id,
                'package_type' => $restaurantPayment->package_type,
                'transaction_id' => $transactionId,
                'global_subscription_id' => $subscription->id,
                'gateway_name' => 'tap',
                'amount' => $restaurantPayment->amount,
                'total' => $restaurantPayment->amount,
                'status' => 'active',
                'pay_date' => now()->format('Y-m-d H:i:s'),
                'next_pay_date' => $isRecurring ? ($restaurantPayment->package_type === 'annual' ? now()->addYear() : now()->addMonth()) : null,
            ]);

            DB::commit();

            // Send notifications
            $emailSetting = EmailSetting::first();
            if ($emailSetting && $emailSetting->mail_driver === 'smtp' && $emailSetting->verified) {
                $superadmin = User::withoutGlobalScopes()->whereNull('branch_id')->whereNull('restaurant_id')->first();
                if ($superadmin) {
                    Notification::send($superadmin, new RestaurantUpdatedPlan($restaurant, $package->id));
                }

                // Notify restaurant admin
                $restaurantAdmin = $restaurant->restaurantAdmin($restaurant);
                if ($restaurantAdmin) {
                    Notification::send($restaurantAdmin, new RestaurantUpdatedPlan($restaurant, $package->id));
                }
            }

            // Clear session
            Session::forget(['tap_payment_id', 'tap_invoice_id', 'tap_package_id', 'tap_package_type', 'tap_amount', 'tap_restaurant_id']);
            session()->forget('restaurant');
            request()->session()->flash('flash.banner', __('messages.planUpgraded'));
            request()->session()->flash('flash.bannerStyle', 'success');
            request()->session()->flash('flash.link', route('settings.index', ['tab' => 'billing']));
            return redirect()->route('dashboard')->with('livewire', true);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tap Plan: Error processing payment: ' . $e->getMessage(), [
                'payment_id' => $restaurantPayment->id,
                'trace' => $e->getTraceAsString()
            ]);
            Session::put('error', __('messages.paymentFailed'));
            return redirect()->route('pricing.plan');
        }
    }

    /**
     * Validate hashstring from Tap webhook
     * 
     * @param array $webhookData The webhook data from Tap
     * @param string $secretKey The secret key for HMAC
     * @param string $postedHashString The hashstring from request headers
     * @return bool True if valid, false otherwise
     */
    private function validateHashstring(array $webhookData, string $secretKey, ?string $postedHashString): bool
    {
        if (!$postedHashString) {
            Log::warning('Tap Plan Webhook: No hashstring provided in headers');
            return false;
        }

        // Determine object type (charge, invoice, authorize, refund)
        $objectType = $webhookData['object'] ?? null;
        $id = $webhookData['id'] ?? null;
        $amount = $webhookData['amount'] ?? null;
        $currency = $webhookData['currency'] ?? null;
        $status = $webhookData['status'] ?? null;

        if (!$id || !$amount || !$currency || !$status) {
            Log::warning('Tap Plan Webhook: Missing required fields for hashstring validation', [
                'id' => $id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $status
            ]);
            return false;
        }

        // Round amount according to currency (standard decimal places)
        // AED, QAR, SAR, USD, EUR, GBP, EGP: 2 decimal places
        // BHD, KWD, OMR, JOD: 3 decimal places
        $currencyDecimals = in_array(strtoupper($currency), ['BHD', 'KWD', 'OMR', 'JOD']) ? 3 : 2;
        $amountRounded = number_format((float)$amount, $currencyDecimals, '.', '');

        // Build hashstring based on object type
        if ($objectType === 'invoice') {
            // Invoice hashstring: x_id{id}x_amount{amount}x_currency{currency}x_updated{updated}x_status{status}x_created{created}
            $updated = $webhookData['updated'] ?? '';
            $created = $webhookData['created'] ?? '';
            
            $toBeHashedString = 'x_id' . $id
                . 'x_amount' . $amountRounded
                . 'x_currency' . $currency
                . 'x_updated' . $updated
                . 'x_status' . $status
                . 'x_created' . $created;
        } else {
            // Charge/Authorize/Refund hashstring: x_id{id}x_amount{amount}x_currency{currency}x_gateway_reference{gateway_reference}x_payment_reference{payment_reference}x_status{status}x_created{created}
            $gatewayReference = $webhookData['reference']['gateway'] ?? '';
            $paymentReference = $webhookData['reference']['payment'] ?? '';
            
            // Get created timestamp - can be from transaction.created or directly from created
            $created = $webhookData['transaction']['created'] ?? $webhookData['created'] ?? '';
            
            $toBeHashedString = 'x_id' . $id
                . 'x_amount' . $amountRounded
                . 'x_currency' . $currency
                . 'x_gateway_reference' . $gatewayReference
                . 'x_payment_reference' . $paymentReference
                . 'x_status' . $status
                . 'x_created' . $created;
        }

        // Calculate hashstring using HMAC SHA256
        $calculatedHashString = hash_hmac('sha256', $toBeHashedString, $secretKey);

        // Compare hashstrings
        if ($calculatedHashString !== $postedHashString) {
            Log::error('Tap Plan Webhook: Hashstring validation failed', [
                'object_type' => $objectType,
                'calculated' => $calculatedHashString,
                'received' => $postedHashString,
                'to_be_hashed' => $toBeHashedString
            ]);
            return false;
        }

        Log::info('Tap Plan Webhook: Hashstring validation successful', [
            'object_type' => $objectType,
            'id' => $id
        ]);

        return true;
    }

    /**
     * Handle plan payment webhook (for both charges and invoices)
     */
    public function handleWebhook(Request $request)
    {
        Log::info('Tap Plan Webhook Received:', $request->all());

        try {
            $webhookData = $request->all();
            if ($request->isJson()) {
                $webhookData = $request->json()->all();
            }

            // Get Tap credentials for hashstring validation
            $credentials = $this->getTapCredentials();
            $secretKey = $credentials['secret_key'];

            // Get hashstring from headers
            $postedHashString = $request->header('hashstring');

            // Validate hashstring
            if (!$this->validateHashstring($webhookData, $secretKey, $postedHashString)) {
                Log::error('Tap Plan Webhook: Hashstring validation failed');
                return response()->json(['status' => 'error', 'message' => 'Invalid hashstring'], 403);
            }

            // Check if this is an invoice webhook or charge webhook
            $invoiceId = $webhookData['id'] ?? null;
            $chargeId = $webhookData['id'] ?? null;
            $status = $webhookData['status'] ?? null;
            $objectType = $webhookData['object'] ?? null; // Tap may include object type

            // Determine if this is an invoice or charge
            $isInvoice = strpos($invoiceId ?? '', 'inv_') === 0 || $objectType === 'invoice';
            $isCharge = strpos($chargeId ?? '', 'chg_') === 0 || $objectType === 'charge' || $objectType === null;

            if (!$invoiceId && !$chargeId || !$status) {
                Log::warning('Tap Plan Webhook: Missing transaction ID or status', ['webhookData' => $webhookData]);
                return response()->json(['status' => 'error', 'message' => 'Missing transaction ID or status'], 400);
            }

            $transactionId = $isInvoice ? $invoiceId : $chargeId;

            // Find restaurant payment by transaction_id
            $restaurantPayment = RestaurantPayment::where('transaction_id', $transactionId)->first();

            if (!$restaurantPayment) {
                Log::warning('Tap Plan Webhook: Restaurant payment not found', ['transaction_id' => $transactionId]);
                return response()->json(['status' => 'error', 'message' => 'Payment record not found'], 404);
            }

            if ($restaurantPayment->status === 'paid') {
                Log::info('Tap Plan Webhook: Payment already processed', ['transaction_id' => $transactionId]);
                return response()->json(['status' => 'success', 'message' => 'Already processed']);
            }

            // Handle invoice webhook (subscription recurring payment)
            if ($isInvoice && $status === 'PAID') {
                return $this->processRecurringPayment($restaurantPayment, $transactionId, $webhookData);
            }

            // Handle charge webhook (one-time payment)
            if ($isCharge && ($status === 'CAPTURED' || $status === 'PAID')) {
                return $this->processWebhookPayment($restaurantPayment, $transactionId);
            }

            return response()->json(['status' => 'success', 'message' => 'Webhook received']);
        } catch (\Exception $e) {
            Log::error('Tap Plan Webhook: Exception: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Process webhook payment (one-time)
     */
    private function processWebhookPayment($restaurantPayment, $transactionId)
    {
        DB::beginTransaction();

        try {
            $restaurantPayment->status = 'paid';
            $restaurantPayment->payment_date_time = now();
            $restaurantPayment->save();

            $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
            $package = Package::find($restaurantPayment->package_id);
            $isRecurring = $restaurantPayment->package_type !== 'lifetime';

            // Update restaurant package
            $restaurant->package_id = $restaurantPayment->package_id;
            $restaurant->package_type = $restaurantPayment->package_type;
            $restaurant->trial_ends_at = null;
            $restaurant->is_active = true;
            $restaurant->status = 'active';
            $restaurant->license_expire_on = null;
            $restaurant->license_updated_at = now();
            $restaurant->save();

            clearRestaurantModulesCache($restaurant->id);

            // Deactivate existing subscriptions
            GlobalSubscription::where('restaurant_id', $restaurant->id)
                ->where('subscription_status', 'active')
                ->update(['subscription_status' => 'inactive']);

            // Create GlobalSubscription
            $subscription = GlobalSubscription::create([
                'restaurant_id' => $restaurant->id,
                'package_id' => $package->id,
                'currency_id' => $restaurantPayment->currency_id,
                'package_type' => $restaurantPayment->package_type,
                'transaction_id' => $transactionId,
                'gateway_name' => 'tap',
                'subscription_status' => 'active',
                'subscribed_on_date' => now(),
                'ends_at' => $isRecurring ? ($restaurantPayment->package_type === 'annual' ? now()->addYear() : now()->addMonth()) : null,
                'quantity' => 1,
            ]);

            // Create GlobalInvoice
            GlobalInvoice::create([
                'restaurant_id' => $restaurant->id,
                'package_id' => $package->id,
                'currency_id' => $restaurantPayment->currency_id,
                'package_type' => $restaurantPayment->package_type,
                'transaction_id' => $transactionId,
                'global_subscription_id' => $subscription->id,
                'gateway_name' => 'tap',
                'amount' => $restaurantPayment->amount,
                'total' => $restaurantPayment->amount,
                'status' => 'active',
                'pay_date' => now()->format('Y-m-d H:i:s'),
                'next_pay_date' => $isRecurring ? ($restaurantPayment->package_type === 'annual' ? now()->addYear() : now()->addMonth()) : null,
            ]);

            DB::commit();

            // Send notifications
            $emailSetting = EmailSetting::first();
            if ($emailSetting && $emailSetting->mail_driver === 'smtp' && $emailSetting->verified) {
                $superadmin = User::withoutGlobalScopes()->whereNull('branch_id')->whereNull('restaurant_id')->first();
                if ($superadmin) {
                    Notification::send($superadmin, new RestaurantUpdatedPlan($restaurant, $package->id));
                }

                // Notify restaurant admin
                $restaurantAdmin = $restaurant->restaurantAdmin($restaurant);
                if ($restaurantAdmin) {
                    Notification::send($restaurantAdmin, new RestaurantUpdatedPlan($restaurant, $package->id));
                }
            }

            Log::info('Tap Plan Webhook: Payment processed successfully', ['transaction_id' => $transactionId]);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tap Plan Webhook: Error processing payment: ' . $e->getMessage(), [
                'transaction_id' => $transactionId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => 'Processing failed'], 500);
        }
    }

    /**
     * Process recurring payment webhook (for subscription invoices)
     */
    private function processRecurringPayment($restaurantPayment, $invoiceId, $webhookData)
    {
        try {
            // Find existing subscription
            $subscription = GlobalSubscription::where('restaurant_id', $restaurantPayment->restaurant_id)
                ->where('gateway_name', 'tap')
                ->where('subscription_status', 'active')
                ->latest()
                ->first();

            if (!$subscription) {
                Log::warning('Tap Plan Webhook: Subscription not found for recurring payment', [
                    'invoice_id' => $invoiceId,
                    'restaurant_id' => $restaurantPayment->restaurant_id
                ]);
                return response()->json(['status' => 'error', 'message' => 'Subscription not found'], 404);
            }

            // Extract charge ID from invoice if available
            $charges = $webhookData['charges'] ?? [];
            $chargeId = null;
            foreach ($charges as $charge) {
                if (isset($charge['id']) && isset($charge['status']) && in_array($charge['status'], ['CAPTURED', 'PAID'])) {
                    $chargeId = $charge['id'];
                    break;
                }
            }

            // Get amount from webhook or use payment amount
            $amount = $webhookData['amount'] ?? $restaurantPayment->amount;
            $package = Package::find($restaurantPayment->package_id);
            $packageType = $restaurantPayment->package_type;

            // Check if invoice already processed
            $existingInvoice = GlobalInvoice::where('gateway_name', 'tap')
                ->where('transaction_id', $chargeId ?? $invoiceId)
                ->first();

            if ($existingInvoice) {
                Log::info('Tap Plan Webhook: Recurring payment already processed', [
                    'invoice_id' => $invoiceId,
                    'charge_id' => $chargeId
                ]);
                return response()->json(['status' => 'success', 'message' => 'Already processed']);
            }

            // Create new invoice for recurring payment
            $today = now();
            $nextPaymentDate = $packageType === 'annual' ? $today->copy()->addYear() : $today->copy()->addMonth();

            $recurringInvoice = GlobalInvoice::create([
                'restaurant_id' => $restaurantPayment->restaurant_id,
                'package_id' => $package->id,
                'currency_id' => $restaurantPayment->currency_id,
                'package_type' => $packageType,
                'transaction_id' => $chargeId ?? $invoiceId,
                'global_subscription_id' => $subscription->id,
                'gateway_name' => 'tap',
                'amount' => $amount,
                'total' => $amount,
                'status' => 'active',
                'pay_date' => $today->format('Y-m-d H:i:s'),
                'next_pay_date' => $nextPaymentDate->format('Y-m-d'),
            ]);

            // Update restaurant status
            $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
            $restaurant->status = 'active';
            $restaurant->save();

            // Send notifications
            $emailSetting = EmailSetting::first();
            if ($emailSetting && $emailSetting->mail_driver === 'smtp' && $emailSetting->verified) {
                $superadmin = User::withoutGlobalScopes()->whereNull('branch_id')->whereNull('restaurant_id')->first();
                if ($superadmin) {
                    Notification::send($superadmin, new RestaurantUpdatedPlan($restaurant, $package->id));
                }
            }

            Log::info('Tap Plan Webhook: Recurring payment processed successfully', [
                'invoice_id' => $invoiceId,
                'charge_id' => $chargeId
            ]);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Tap Plan Webhook: Error processing recurring payment: ' . $e->getMessage(), [
                'invoice_id' => $invoiceId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => 'Processing failed'], 500);
        }
    }
}
