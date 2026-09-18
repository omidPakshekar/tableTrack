<?php

namespace App\Http\Controllers\SuperAdmin;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Package;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use App\Models\GlobalInvoice;
use App\Models\RestaurantPayment;
use App\Models\GlobalSubscription;
use App\Http\Controllers\Controller;
use App\Models\EmailSetting;
use App\Models\SuperadminPaymentGateway;
use App\Notifications\RestaurantUpdatedPlan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Mollie\Api\MollieApiClient;

class MollieController extends Controller
{
    private $mollie;
    private $apiKey;

    /**
     * Load Mollie API credentials from DB
     */
    private function setMollieConfigs(): void
    {
        $gateway = SuperadminPaymentGateway::first();

        if (!$gateway) {
            throw new \Exception("No SuperadminPaymentGateway found");
        }

        $isTest = $gateway->mollie_mode === 'test';

        $this->apiKey = $isTest
            ? $gateway->test_mollie_key
            : $gateway->live_mollie_key;

        if (empty($this->apiKey)) {
            throw new \Exception("Mollie API key not configured");
        }

        $this->mollie = new MollieApiClient();
        $this->mollie->setApiKey($this->apiKey);
    }

    /**
     * Handle payment initiation from form submission
     */
    public function initiatePayment(Request $request)
    {
        try {
            $this->setMollieConfigs();

            $restaurantPayment = RestaurantPayment::findOrFail($request->payment_id);
            $package = Package::findOrFail($request->package_id);
            $restaurant = Restaurant::findOrFail($request->restaurant_id);

            // Create or get customer
            $customerId = $this->createCustomer($restaurant);

            // Format amount for Mollie
            $amountValue = number_format($restaurantPayment->amount, 2, '.', '');

            // Determine if this is a recurring payment
            $isRecurring = $restaurantPayment->package_type !== 'lifetime';

            // Create payment in Mollie
            $paymentData = [
                'amount' => [
                    'currency' => $request->currency,
                    'value' => $amountValue,
                ],
                'description' => "Plan Payment - {$package->name} ({$restaurantPayment->package_type})",
                'redirectUrl' => route('mollie.plan.success', ['payment_id' => $restaurantPayment->id]),
                'webhookUrl' => route('mollie.plan.webhook'),
                'customerId' => $customerId,
            ];

            // For recurring payments, set sequenceType to 'first' to establish mandate
            if ($isRecurring) {
                $paymentData['sequenceType'] = 'first';
            }

            $molliePayment = $this->mollie->payments->create($paymentData);

            // Update payment record with Mollie payment ID
            $restaurantPayment->update([
                'transaction_id' => $molliePayment->id,
                'mollie_customer_id' => $customerId,
            ]);

            // Store payment ID in session
            session(['mollie_payment_id' => $restaurantPayment->id]);

            // Redirect to Mollie checkout
            return redirect($molliePayment->getCheckoutUrl());

        } catch (\Exception $e) {
            Log::error('Mollie: Payment initiation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('dashboard')->with('error', 'Failed to initiate payment: ' . $e->getMessage());
        }
    }

    /**
     * Get or create Mollie customer
     */
    private function createCustomer(Restaurant $restaurant): string
    {
        // If restaurant already has a Mollie customer ID, return it
        if ($restaurant->mollie_customer_id) {
            Log::info('Mollie: Using existing customer', [
                'customer_id' => $restaurant->mollie_customer_id,
                'restaurant_id' => $restaurant->id
            ]);
            return $restaurant->mollie_customer_id;
        }

        $email = user()->email ?? $restaurant->email;
        $name = user()->name ?? $restaurant->name;

        try {
            // Create customer in Mollie
            $customer = $this->mollie->customers->create([
                "name" => $name,
                "email" => $email
            ]);

            // Save customer ID to restaurant
            $restaurant->mollie_customer_id = $customer->id;
            $restaurant->save();

            Log::info('Mollie: Customer created successfully', [
                'customer_id' => $customer->id,
                'restaurant_id' => $restaurant->id,
                'email' => $email
            ]);

            return $customer->id;
        } catch (\Exception $e) {
            Log::error('Mollie: Customer creation failed', [
                'restaurant_id' => $restaurant->id,
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Failed to create Mollie customer: " . $e->getMessage());
        }
    }

    /**
     * Handle payment success callback
     */
    public function paymentSuccess(Request $request)
    {
        try {
            $this->setMollieConfigs();

            $paymentId = $request->input('payment_id') ?? session('mollie_payment_id');

            if (!$paymentId) {
                Log::error('Mollie: No payment ID provided in success callback');
                return redirect()->route('dashboard')->with('error', 'Payment ID not found');
            }

            $restaurantPayment = RestaurantPayment::find($paymentId);

            if (!$restaurantPayment) {
                Log::error('Mollie: Restaurant payment not found', ['payment_id' => $paymentId]);
                return redirect()->route('dashboard')->with('error', 'Payment record not found');
            }

            if (!$restaurantPayment->transaction_id) {
                Log::error('Mollie: Mollie payment ID not found in restaurant payment', ['payment_id' => $paymentId]);
                return redirect()->route('dashboard')->with('error', 'Mollie payment ID not found');
            }

            // Retrieve payment from Mollie
            $molliePayment = $this->mollie->payments->get($restaurantPayment->transaction_id);

            if (!$molliePayment->isPaid()) {
                Log::warning('Mollie: Payment not paid', [
                    'payment_id' => $restaurantPayment->transaction_id,
                    'status' => $molliePayment->status
                ]);
                return redirect()->route('dashboard')->with('error', 'Payment not completed');
            }

            // Update restaurant payment status
            $restaurantPayment->status = 'paid';
            $restaurantPayment->payment_date_time = now();
            $restaurantPayment->save();

            $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
            $package = Package::find($restaurantPayment->package_id);
            $isRecurring = $restaurantPayment->package_type !== 'lifetime';

            DB::beginTransaction();

            try {
                // Update restaurant package details
                $restaurant->package_id = $restaurantPayment->package_id;
                $restaurant->package_type = $restaurantPayment->package_type;
                $restaurant->trial_ends_at = null;
                $restaurant->is_active = true;
                $restaurant->status = 'active';
                $restaurant->license_expire_on = null;
                $restaurant->license_updated_at = now();
                $restaurant->subscription_updated_at = now();
                $restaurant->save();

                // Clear restaurant modules cache
                clearRestaurantModulesCache($restaurant->id);

                // Deactivate existing subscriptions
                GlobalSubscription::where('restaurant_id', $restaurant->id)
                    ->where('subscription_status', 'active')
                    ->update(['subscription_status' => 'inactive']);

            $mollieSubscriptionId = null;

            // For recurring payments, create subscription after mandate is established
            if ($isRecurring && $molliePayment->customerId) {
                // Try to create subscription - if mandate not ready, it will be created via webhook
                $mollieSubscriptionId = $this->createSubscription($molliePayment, $restaurant, $package, $restaurantPayment);
            }

                // Create GlobalSubscription record
                $subscription = GlobalSubscription::create([
                    'restaurant_id' => $restaurant->id,
                    'package_id' => $package->id,
                    'currency_id' => $restaurantPayment->currency_id,
                    'package_type' => $restaurantPayment->package_type,
                    'transaction_id' => $molliePayment->id,
                    'gateway_name' => 'mollie',
                    'subscription_status' => 'active',
                    'subscribed_on_date' => now(),
                    'ends_at' => $isRecurring ? ($restaurantPayment->package_type === 'annual' ? now()->addYear() : now()->addMonth()) : null,
                    'quantity' => 1,
                ]);

                // Update restaurant payment with subscription ID if available
                if ($mollieSubscriptionId) {
                    $restaurantPayment->mollie_subscription_id = $mollieSubscriptionId;
                    $restaurantPayment->save();
                }

                // Create GlobalInvoice
                GlobalInvoice::create([
                    'restaurant_id' => $restaurant->id,
                    'package_id' => $package->id,
                    'currency_id' => $restaurantPayment->currency_id,
                    'package_type' => $restaurantPayment->package_type,
                    'transaction_id' => $molliePayment->id,
                    'global_subscription_id' => $subscription->id,
                    'gateway_name' => 'mollie',
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
                    $generatedBy = User::withoutGlobalScopes()->whereNull('restaurant_id')->first();
                    if ($generatedBy) {
                        Notification::send($generatedBy, new RestaurantUpdatedPlan($restaurant, $package->id));
                    }

                    $restaurantAdmin = Restaurant::restaurantAdmin($restaurant);
                    if ($restaurantAdmin) {
                        Notification::send($restaurantAdmin, new RestaurantUpdatedPlan($restaurant, $package->id));
                    }
                }

                session()->forget('mollie_payment_id');
                session()->flash('flash.banner', __('messages.planUpgraded'));
                session()->flash('flash.bannerStyle', 'success');
                session()->flash('flash.link', route('settings.index', ['tab' => 'billing']));

                return redirect()->route('dashboard');

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Mollie: Error processing payment success', [
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Mollie: Payment success callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('dashboard')->with('error', 'Error processing payment: ' . $e->getMessage());
        }
    }

    /**
     * Create subscription in Mollie after mandate is established
     */
    private function createSubscription($molliePayment, Restaurant $restaurant, Package $package, RestaurantPayment $restaurantPayment): ?string
    {
        try {
            $customerId = $molliePayment->customerId;

            if (!$customerId) {
                Log::warning('Mollie: No customer ID in payment', ['payment_id' => $molliePayment->id]);
                return null;
            }

            // Check if mandate exists and is valid
            $customer = $this->mollie->customers->get($customerId);
            $mandates = $customer->mandates();

            $validMandate = null;
            foreach ($mandates as $mandate) {
                if ($mandate->status === 'valid') {
                    $validMandate = $mandate;
                    break;
                }
            }

            if (!$validMandate) {
                Log::warning('Mollie: No valid mandate found for customer', ['customer_id' => $customerId]);
                // Mandate will be created after first payment, so we'll handle subscription creation in webhook
                return null;
            }

            // Determine interval based on package type
            $interval = $restaurantPayment->package_type === 'annual' ? '12 months' : '1 month';

            // Format amount
            $amountValue = number_format($restaurantPayment->amount, 2, '.', '');

            // Create subscription
            $subscription = $customer->createSubscription([
                'amount' => [
                    'currency' => $package->currency->currency_code,
                    'value' => $amountValue,
                ],
                'interval' => $interval,
                'description' => "Subscription - {$package->name} ({$restaurantPayment->package_type})",
                'webhookUrl' => route('mollie.plan.webhook'),
            ]);

            Log::info('Mollie: Subscription created successfully', [
                'subscription_id' => $subscription->id,
                'customer_id' => $customerId,
                'restaurant_id' => $restaurant->id
            ]);

            return $subscription->id;

        } catch (\Exception $e) {
            Log::error('Mollie: Failed to create subscription', [
                'restaurant_id' => $restaurant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw - subscription can be created later via webhook
            return null;
        }
    }

    /**
     * Handle Mollie webhook for subscription payments
     */
    public function handleWebhook(Request $request)
    {
        try {
            $this->setMollieConfigs();

            Log::info('Mollie: Webhook received', ['data' => $request->all()]);

            $paymentId = $request->input('id');

            if (!$paymentId) {
                Log::error('Mollie: No payment ID in webhook');
                return response()->json(['error' => 'No payment ID'], 400);
            }

            // Retrieve payment from Mollie
            $molliePayment = $this->mollie->payments->get($paymentId);

            // Check if this is a subscription payment
            if ($molliePayment->subscriptionId) {
                return $this->handleSubscriptionPayment($molliePayment);
            }

            // Handle regular payment webhook
            $restaurantPayment = RestaurantPayment::where('transaction_id', $paymentId)->first();

            if (!$restaurantPayment) {
                Log::warning('Mollie: Restaurant payment not found for webhook', ['payment_id' => $paymentId]);
                return response()->json(['message' => 'Payment not found'], 404);
            }

            if ($molliePayment->isPaid() && $restaurantPayment->status !== 'paid') {
                // Payment was successful, process it
                $this->processPaymentSuccess($restaurantPayment, $molliePayment);

                // For recurring payments, try to create subscription if not already created
                if ($restaurantPayment->package_type !== 'lifetime' &&
                    $molliePayment->customerId &&
                    !$restaurantPayment->mollie_subscription_id) {
                    $package = Package::find($restaurantPayment->package_id);
                    $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
                    $subscriptionId = $this->createSubscription($molliePayment, $restaurant, $package, $restaurantPayment);
                    if ($subscriptionId) {
                        $restaurantPayment->mollie_subscription_id = $subscriptionId;
                        $restaurantPayment->save();
                    }
                }
            } elseif ($molliePayment->isFailed() || $molliePayment->isCanceled()) {
                Log::info('Mollie: Payment failed or canceled', [
                    'payment_id' => $paymentId,
                    'status' => $molliePayment->status
                ]);
            }

            return response()->json(['message' => 'Webhook processed']);

        } catch (\Exception $e) {
            Log::error('Mollie: Webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Handle subscription payment webhook
     */
    private function handleSubscriptionPayment($molliePayment)
    {
        try {
            $subscriptionId = $molliePayment->subscriptionId;

            // Find subscription by Mollie subscription ID in restaurant_payments
            $restaurantPayment = RestaurantPayment::where('mollie_subscription_id', $subscriptionId)->first();

            if ($restaurantPayment) {
                // Find or create GlobalSubscription
                $subscription = GlobalSubscription::where('restaurant_id', $restaurantPayment->restaurant_id)
                    ->where('gateway_name', 'mollie')
                    ->where('subscription_status', 'active')
                    ->latest()
                    ->first();

                if (!$subscription) {
                    // Create subscription if it doesn't exist
                    $package = Package::find($restaurantPayment->package_id);
                    $subscription = GlobalSubscription::create([
                        'restaurant_id' => $restaurantPayment->restaurant_id,
                        'package_id' => $restaurantPayment->package_id,
                        'currency_id' => $restaurantPayment->currency_id,
                        'package_type' => $restaurantPayment->package_type,
                        'transaction_id' => $subscriptionId,
                        'gateway_name' => 'mollie',
                        'subscription_status' => 'active',
                        'subscribed_on_date' => now(),
                        'ends_at' => $restaurantPayment->package_type === 'annual' ? now()->addYear() : now()->addMonth(),
                        'quantity' => 1,
                    ]);
                }
            } else {
                // Try to find by transaction_id
                $subscription = GlobalSubscription::where('transaction_id', $subscriptionId)
                    ->where('gateway_name', 'mollie')
                    ->first();

                if (!$subscription) {
                    Log::warning('Mollie: Subscription not found for webhook', ['subscription_id' => $subscriptionId]);
                    return response()->json(['message' => 'Subscription not found'], 404);
                }
            }

            if ($molliePayment->isPaid()) {
                // Create invoice for recurring payment
                GlobalInvoice::create([
                    'restaurant_id' => $subscription->restaurant_id,
                    'package_id' => $subscription->package_id,
                    'currency_id' => $subscription->currency_id,
                    'package_type' => $subscription->package_type,
                    'transaction_id' => $molliePayment->id,
                    'global_subscription_id' => $subscription->id,
                    'gateway_name' => 'mollie',
                    'amount' => (float) $molliePayment->amount->value,
                    'total' => (float) $molliePayment->amount->value,
                    'status' => 'active',
                    'pay_date' => now()->format('Y-m-d H:i:s'),
                    'next_pay_date' => $subscription->package_type === 'annual' ? now()->addYear() : now()->addMonth(),
                ]);

                // Update subscription ends_at
                $subscription->ends_at = $subscription->package_type === 'annual' ? now()->addYear() : now()->addMonth();
                $subscription->save();

                Log::info('Mollie: Subscription payment processed', [
                    'subscription_id' => $subscriptionId,
                    'payment_id' => $molliePayment->id
                ]);
            }

            return response()->json(['message' => 'Subscription payment processed']);

        } catch (\Exception $e) {
            Log::error('Mollie: Subscription payment webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Subscription payment processing failed'], 500);
        }
    }

    /**
     * Process payment success (used by webhook)
     */
    private function processPaymentSuccess(RestaurantPayment $restaurantPayment, $molliePayment)
    {
        DB::beginTransaction();

        try {
            $restaurantPayment->status = 'paid';
            $restaurantPayment->payment_date_time = now();
            $restaurantPayment->save();

            $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
            $package = Package::find($restaurantPayment->package_id);
            $isRecurring = $restaurantPayment->package_type !== 'lifetime';

            // Update restaurant
            $restaurant->package_id = $restaurantPayment->package_id;
            $restaurant->package_type = $restaurantPayment->package_type;
            $restaurant->trial_ends_at = null;
            $restaurant->is_active = true;
            $restaurant->status = 'active';
            $restaurant->license_expire_on = null;
            $restaurant->license_updated_at = now();
            $restaurant->subscription_updated_at = now();
            $restaurant->save();

            clearRestaurantModulesCache($restaurant->id);

            // For recurring payments, try to create subscription if mandate exists
            // If mandate not ready, subscription will be created when first recurring payment webhook arrives
            if ($isRecurring && $molliePayment->customerId) {
                $mollieSubscriptionId = $this->createSubscription($molliePayment, $restaurant, $package, $restaurantPayment);
                if ($mollieSubscriptionId) {
                    $restaurantPayment->mollie_subscription_id = $mollieSubscriptionId;
                    $restaurantPayment->save();
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Mollie: Error processing payment success in webhook', [
                'payment_id' => $restaurantPayment->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}
