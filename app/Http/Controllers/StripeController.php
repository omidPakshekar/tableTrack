<?php

namespace App\Http\Controllers;

use Error;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\EmailSetting;
use Illuminate\Http\Request;
use App\Models\GlobalInvoice;
use App\Models\StripePayment;
use App\Models\RestaurantPayment;
use App\Models\GlobalSubscription;
use App\Events\SendNewOrderReceived;
use App\Events\SendOrderBillEvent;
use App\Models\SuperadminPaymentGateway;
use App\Notifications\RestaurantUpdatedPlan;
use App\Services\ShopCartKotPrintUrls;
use Illuminate\Support\Facades\Notification;

class StripeController extends Controller
{

    public function orderPayment()
    {
        $milestonePayment = StripePayment::findOrFail(request()->order_payment);
        $paymentGateway = $milestonePayment->order->branch->restaurant->paymentGateways;

        $stripe = new \Stripe\StripeClient($paymentGateway->stripe_secret);

        $checkoutSession = $stripe->checkout->sessions->create([
            'line_items' => [[
                'price_data' => [
                    'currency' => $milestonePayment->order->branch->restaurant->currency->currency_code,
                    'product_data' => [
                        'name' => $milestonePayment->order->show_formatted_order_number,
                    ],
                    'unit_amount' => floatval($milestonePayment->amount) * 100,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => module_enabled('Subdomain') ? url('/') : route('shop_restaurant', ['hash' => $milestonePayment->order->branch->restaurant->hash]),
            'client_reference_id' => $milestonePayment->id
        ]);

        $milestonePayment->stripe_session_id = $checkoutSession->id;
        $milestonePayment->save();

        header('HTTP/1.1 303 See Other');
        return redirect($checkoutSession->url);
    }

    public function success()
    {
        $payment = StripePayment::where('stripe_session_id', request()->session_id)->firstOrFail();

        $paymentGateway = $payment->order->branch->restaurant->paymentGateways;

        $stripe = new \Stripe\StripeClient($paymentGateway->stripe_secret);

        try {
            $session = $stripe->checkout->sessions->retrieve(request()->session_id);

            $this->completeOrderStripePayment($payment, (string) $session->payment_intent);

            $order = Order::find($payment->order_id);

            return $this->redirectAfterOrderStripePayment($order);
        } catch (Error $e) {
            http_response_code(500);
            logger(json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Embedded one-time order payment: create PaymentIntent (restaurant Stripe account).
     */
    public function orderEmbeddedSetup(Request $request)
    {
        $request->validate([
            'stripe_payment_id' => 'required|integer',
        ]);

        $stripePayment = StripePayment::with(['order.branch.restaurant.currency', 'order.branch.restaurant.paymentGateways'])
            ->findOrFail($request->stripe_payment_id);

        if ($stripePayment->payment_status === 'completed') {
            return response()->json(['message' => __('messages.paymentError')], 422);
        }

        $gateway = $stripePayment->order->branch->restaurant->paymentGateways;
        if (!$gateway || !$gateway->stripe_status) {
            return response()->json(['message' => __('messages.noCredentialFound')], 422);
        }

        $currencyCode = strtolower((string) $stripePayment->order->branch->restaurant->currency->currency_code);
        $stripe = new \Stripe\StripeClient($gateway->stripe_secret);

        $intent = $stripe->paymentIntents->create([
            'amount' => (int) round(((float) $stripePayment->amount) * 100),
            'currency' => $currencyCode,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'stripe_payment_id' => (string) $stripePayment->id,
                'order_id' => (string) $stripePayment->order_id,
            ],
        ]);

        $stripePayment->stripe_payment_intent = $intent->id;
        $stripePayment->save();

        return response()->json([
            'publishable_key' => $gateway->stripe_key,
            'client_secret' => $intent->client_secret,
        ]);
    }

    /**
     * Complete embedded order payment after PaymentIntent succeeds on client.
     */
    public function orderEmbeddedReturn(Request $request)
    {
        $request->validate([
            'stripe_payment_id' => 'required|integer',
        ]);

        $payment = StripePayment::with(['order.branch.restaurant.paymentGateways', 'order.branch.restaurant'])
            ->findOrFail($request->stripe_payment_id);

        $gateway = $payment->order->branch->restaurant->paymentGateways;
        $stripe = new \Stripe\StripeClient($gateway->stripe_secret);

        $cancelUrl = module_enabled('Subdomain')
            ? url('/')
            : route('shop_restaurant', ['hash' => $payment->order->branch->restaurant->hash]);

        try {
            if (empty($payment->stripe_payment_intent)) {
                return redirect()->to($cancelUrl)->with([
                    'flash.banner' => __('messages.paymentError'),
                    'flash.bannerStyle' => 'danger',
                ]);
            }

            $intent = $stripe->paymentIntents->retrieve($payment->stripe_payment_intent);
            if (($intent->status ?? null) !== 'succeeded') {
                return redirect()->to($cancelUrl)->with([
                    'flash.banner' => __('messages.paymentError'),
                    'flash.bannerStyle' => 'danger',
                ]);
            }

            $this->completeOrderStripePayment($payment, (string) $intent->id);

            $order = Order::find($payment->order_id);

            return $this->redirectAfterOrderStripePayment($order);
        } catch (\Throwable $e) {
            logger(['orderEmbeddedReturn' => $e->getMessage()]);

            return redirect()->to($cancelUrl)->with([
                'flash.banner' => __('messages.paymentError'),
                'flash.bannerStyle' => 'danger',
            ]);
        }
    }

    private function completeOrderStripePayment(StripePayment $payment, string $paymentIntentId): void
    {
        $payment->stripe_payment_intent = $paymentIntentId;
        $payment->payment_status = 'completed';
        $payment->payment_date = now()->toDateTimeString();
        $payment->save();

        Payment::updateOrCreate(
            [
                'order_id' => $payment->order_id,
                'payment_method' => 'due',
                'amount' => $payment->amount,
            ],
            [
                'payment_method' => 'stripe',
                'branch_id' => $payment->order->branch_id,
                'transaction_id' => $paymentIntentId,
            ]
        );

        $order = Order::find($payment->order_id);
        $order->amount_paid = $order->amount_paid + $payment->amount;
        $order->status = 'paid';
        $order->save();

        ShopCartKotPrintUrls::flashDeferredKotPrintForShopOrder($order);

        $this->sendNotifications($order);
    }

    private function redirectAfterOrderStripePayment(Order $order)
    {
        if ($order->placed_via === 'kiosk') {
            return redirect()->route('kiosk.order-confirmation', $order->uuid)->with([
                'flash.banner' => __('messages.paymentDoneSuccessfully'),
                'flash.bannerStyle' => 'success',
            ]);
        }

        return redirect()->route('order_success', $order->uuid);
    }

    public function licensePayment(Request $request)
    {
        $paymentGateway = SuperadminPaymentGateway::first();
        $restaurantPayment = RestaurantPayment::findOrFail($request->license_payment);
        $restaurant = Restaurant::find($restaurantPayment->restaurant_id);
        $stripe = new \Stripe\StripeClient($paymentGateway->stripe_secret);
        if (!$restaurant->stripe_id) {
            $customer = $stripe->customers->create([
                'name' => $restaurant->name,
                'email' => $restaurant->email,
            ]);
            $restaurant->stripe_id = $customer->id;
            $restaurant->save();
        }

        $package = Package::find($request->input('package_id'));

        if ($package->package_type->value === 'standard') {
            $planType = $request->input('package_type');
            $priceId = $planType === 'annual' ? $package->stripe_annual_plan_id : $package->stripe_monthly_plan_id;

            // Check if the price ID exists in Stripe
            if (!isset($priceId) || trim($priceId) === '') {
                return redirect()->route('dashboard')->with([
                    'flash.banner' => __('messages.invalidStripePlan'),
                    'flash.bannerStyle' => 'danger'
                ]);
            }

            try {
                $stripe->prices->retrieve($priceId);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                return redirect()->route('dashboard')->with([
                    'flash.banner' => $e->getMessage(),
                    'flash.bannerStyle' => 'danger'
                ]);
            }

            $session = $stripe->checkout->sessions->create([
                'customer' => $restaurant->stripe_id,
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'success_url' => route('stripe.license_success') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('dashboard'),
                'client_reference_id' => $restaurantPayment->id,
            ]);

            $restaurantPayment->stripe_session_id = $session->id;
            $restaurantPayment->save();
            header('HTTP/1.1 303 See Other');
            return redirect($session->url);
        } else {
            // Lifetime package or other - use package currency (e.g. TRY) not default USD
            $currencyCode = strtolower($package->currency?->currency_code ?? 'usd');
            $checkoutSession = $stripe->checkout->sessions->create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currencyCode,
                        'product_data' => [
                            'name' => 'License Payment for ' . global_setting()->name,
                        ],
                        'unit_amount' => floatval($restaurantPayment->amount * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('stripe.license_success') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('dashboard'),
                'client_reference_id' => $restaurantPayment->id
            ]);

            $restaurantPayment->stripe_session_id = $checkoutSession->id;
            $restaurantPayment->package_type = 'lifetime';
            $restaurantPayment->save();
            header('HTTP/1.1 303 See Other');
            return redirect($checkoutSession->url);
        }
    }

    public function licenseSuccess()
    {
        $paymentGateway = SuperadminPaymentGateway::first();
        $payment = RestaurantPayment::where('stripe_session_id', request()->session_id)->firstOrFail();
        $stripe = new \Stripe\StripeClient($paymentGateway->stripe_secret);

        try {
            $session = $stripe->checkout->sessions->retrieve(request()->session_id);

            $planId = null;
            $subscriptionId = null;
            // Retrieve the invoice and payment intent
            if ($session->invoice) {
                $invoice = $stripe->invoices->retrieve($session->invoice);

                $paymentIntent = $invoice->payment_intent;
                $lineItem = $invoice->lines->data[0];
                $planId = $lineItem->plan->id;
                $subscriptionId = $lineItem->subscription;
            } else {
                $paymentIntent = $session->payment_intent;
            }


            $payment->stripe_payment_intent = $paymentIntent;
            $payment->status = 'paid';
            $payment->payment_date_time = now()->toDateTimeString();
            $payment->save();

            $restaurant = Restaurant::find($payment->restaurant_id);
            $restaurant->package_id = $payment->package_id;
            $restaurant->package_type = $payment->package_type;
            $restaurant->trial_ends_at = null;
            $restaurant->is_active = true;
            $restaurant->status = 'active';
            $restaurant->license_expire_on = null;
            $restaurant->save();
            // Ensure feature/module cache reflects new plan immediately
            clearRestaurantModulesCache($restaurant->id);

            GlobalSubscription::where('restaurant_id', $restaurant->id)
                ->where('subscription_status', 'active')
                ->update(['subscription_status' => 'inactive']);

            // Create new Subscription entry
            $subscription = new GlobalSubscription();
            $subscription->transaction_id = $paymentIntent;
            $subscription->restaurant_id = $restaurant->id;
            $subscription->package_type = $restaurant->package_type;
            $subscription->currency_id = $payment->currency_id;
            $subscription->stripe_id = $restaurant->stripe_id;
            $subscription->quantity = 1;
            $subscription->package_id = $restaurant->package_id;
            $subscription->gateway_name = 'stripe';
            $subscription->subscription_status = 'active';
            $subscription->subscription_id = $subscriptionId;
            $subscription->ends_at = $restaurant->license_expire_on ?? null;
            $subscription->subscribed_on_date = now()->format('Y-m-d H:i:s');
            $subscription->save();


            // Check if the invoice already exists and update or create accordingly
            if ($subscription) {
                $invoice = GlobalInvoice::updateOrCreate(
                    ['transaction_id' => $subscription->transaction_id],
                    [
                        'restaurant_id' => $restaurant->id,
                        'currency_id' => $subscription->currency_id,
                        'package_id' => $subscription->package_id,
                        'global_subscription_id' => $subscription->id,
                        'package_type' => $subscription->package_type,
                        'plan_id' => $planId,
                        'total' => $payment->amount,
                        'gateway_name' => 'stripe',
                    ]
                );

                if (!$invoice->pay_date) {
                    $invoice->pay_date = now();
                }
                $invoice->next_pay_date = match ($subscription->package_type) {
                    'lifetime' => null,
                    'monthly' => Carbon::parse($invoice->pay_date)->addMonth(),
                    default => Carbon::parse($invoice->pay_date)->addYear(),
                };
                $invoice->save();
            }

            $emailSetting = EmailSetting::first();
            if ($emailSetting->mail_driver === 'smtp' && $emailSetting->verified) {
                // Notify superadmin
                $generatedBy = User::withoutGlobalScopes()->whereNull('branch_id')->whereNull('restaurant_id')->first();
                Notification::send($generatedBy, new RestaurantUpdatedPlan($restaurant, $subscription->package_id));

                // Notify restaurant admin
                $restaurantAdmin = $restaurant->restaurantAdmin($restaurant);
                Notification::send($restaurantAdmin, new RestaurantUpdatedPlan($restaurant, $subscription->package_id));
            }

            session()->forget('restaurant');
            request()->session()->flash('flash.banner', __('messages.planUpgraded'));
            request()->session()->flash('flash.bannerStyle', 'success');
            request()->session()->flash('flash.link', route('settings.index', ['tab' => 'billing']));

            return redirect()->route('dashboard')->with('livewire', true);
        } catch (\Exception $e) {
            logger(['error' => $e->getMessage()]);
            return redirect()->route('dashboard')->with([
                'flash.banner' => __('messages.paymentError'),
                'flash.bannerStyle' => 'danger'
            ]);
        }
    }


    public function sendNotifications($order)
    {
        SendNewOrderReceived::dispatch($order);

        if ($order->customer_id) {
            SendOrderBillEvent::dispatch($order);
        }
    }

    /**
     * Create Stripe subscription/payment intent and return client secret
     * for embedded card payments (no redirect).
     */
    public function licenseEmbeddedSetup(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|integer',
        ]);

        $gateway = SuperadminPaymentGateway::first();
        if (!$gateway || !$gateway->stripe_status) {
            return response()->json(['message' => __('messages.noCredentialFound')], 422);
        }

        $payment = RestaurantPayment::findOrFail($request->payment_id);
        $restaurant = Restaurant::findOrFail($payment->restaurant_id);
        $package = Package::findOrFail($payment->package_id);

        $stripe = new \Stripe\StripeClient($gateway->stripe_secret);

        if (!$restaurant->stripe_id) {
            $customer = $stripe->customers->create([
                'name' => $restaurant->name,
                'email' => $restaurant->email,
            ]);
            $restaurant->stripe_id = $customer->id;
            $restaurant->save();
        }

        // Lifetime = one-time PaymentIntent, Standard = Subscription (monthly/annual)
        if ($package->package_type->value === 'lifetime') {
            $currencyCode = strtolower($package->currency?->currency_code ?? 'usd');
            $intent = $stripe->paymentIntents->create([
                'amount' => (int) round(((float) $payment->amount) * 100),
                'currency' => $currencyCode,
                'customer' => $restaurant->stripe_id,
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => [
                    'restaurant_payment_id' => $payment->id,
                    'restaurant_id' => $restaurant->id,
                    'package_id' => $package->id,
                    'package_type' => $payment->package_type,
                ],
            ]);

            $payment->stripe_payment_intent = $intent->id;
            $payment->save();

            return response()->json([
                'publishable_key' => $gateway->stripe_key,
                'client_secret' => $intent->client_secret,
                'mode' => 'payment_intent',
            ]);
        }

        $planType = $payment->package_type === 'annual' ? 'annual' : 'monthly';
        $priceId = $planType === 'annual' ? $package->stripe_annual_plan_id : $package->stripe_monthly_plan_id;

        if (!isset($priceId) || trim((string) $priceId) === '') {
            return response()->json(['message' => __('messages.invalidStripePlan')], 422);
        }

        // Create subscription in incomplete state, then confirm its invoice payment intent on client
        $subscription = $stripe->subscriptions->create([
            'customer' => $restaurant->stripe_id,
            'items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => [
                'restaurant_payment_id' => $payment->id,
                'restaurant_id' => $restaurant->id,
                'package_id' => $package->id,
                'package_type' => $payment->package_type,
            ],
        ]);

        $intent = $subscription->latest_invoice->payment_intent ?? null;
        if (!$intent || empty($intent->client_secret)) {
            return response()->json(['message' => __('messages.somethingWentWrong')], 422);
        }

        $payment->stripe_session_id = $subscription->id; // storing subscription id (no checkout session in embedded)
        $payment->stripe_payment_intent = $intent->id;
        $payment->save();

        return response()->json([
            'publishable_key' => $gateway->stripe_key,
            'client_secret' => $intent->client_secret,
            'mode' => 'subscription',
        ]);
    }

    /**
     * Finalize embedded Stripe payment and update plan.
     */
    public function licenseEmbeddedReturn(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|integer',
        ]);

        $gateway = SuperadminPaymentGateway::first();
        $payment = RestaurantPayment::findOrFail($request->payment_id);
        $restaurant = Restaurant::findOrFail($payment->restaurant_id);
        $package = Package::findOrFail($payment->package_id);

        $stripe = new \Stripe\StripeClient($gateway->stripe_secret);

        try {
            // Verify payment intent succeeded
            $intent = $stripe->paymentIntents->retrieve($payment->stripe_payment_intent);
            if (($intent->status ?? null) !== 'succeeded') {
                return redirect()->route('dashboard')->with([
                    'flash.banner' => __('messages.paymentError'),
                    'flash.bannerStyle' => 'danger'
                ]);
            }

            $payment->status = 'paid';
            $payment->payment_date_time = now()->toDateTimeString();
            $payment->transaction_id = $intent->id;
            $payment->save();

            // Update restaurant plan (same as licenseSuccess, but without checkout session)
            $restaurant->package_id = $payment->package_id;
            $restaurant->package_type = $payment->package_type;
            $restaurant->trial_ends_at = null;
            $restaurant->is_active = true;
            $restaurant->status = 'active';
            $restaurant->license_expire_on = null;
            $restaurant->save();
            clearRestaurantModulesCache($restaurant->id);

            GlobalSubscription::where('restaurant_id', $restaurant->id)
                ->where('subscription_status', 'active')
                ->update(['subscription_status' => 'inactive']);

            $subscriptionId = null;
            $planId = null;

            if ($payment->stripe_session_id) {
                $subscriptionId = $payment->stripe_session_id;
            }

            // planId to store on invoice (stripe price id)
            if ($package->package_type->value === 'standard') {
                $planId = $payment->package_type === 'annual'
                    ? $package->stripe_annual_plan_id
                    : $package->stripe_monthly_plan_id;
            }

            $subscription = new GlobalSubscription();
            $subscription->transaction_id = $intent->id;
            $subscription->restaurant_id = $restaurant->id;
            $subscription->package_type = $restaurant->package_type;
            $subscription->currency_id = $payment->currency_id;
            $subscription->stripe_id = $restaurant->stripe_id;
            $subscription->quantity = 1;
            $subscription->package_id = $restaurant->package_id;
            $subscription->gateway_name = 'stripe';
            $subscription->subscription_status = 'active';
            $subscription->subscription_id = $subscriptionId;
            $subscription->ends_at = $restaurant->license_expire_on ?? null;
            $subscription->subscribed_on_date = now()->format('Y-m-d H:i:s');
            $subscription->save();

            $invoice = GlobalInvoice::updateOrCreate(
                ['transaction_id' => $subscription->transaction_id],
                [
                    'restaurant_id' => $restaurant->id,
                    'currency_id' => $subscription->currency_id,
                    'package_id' => $subscription->package_id,
                    'global_subscription_id' => $subscription->id,
                    'package_type' => $subscription->package_type,
                    'plan_id' => $planId,
                    'total' => $payment->amount,
                    'gateway_name' => 'stripe',
                ]
            );

            if (!$invoice->pay_date) {
                $invoice->pay_date = now();
            }

            $invoice->next_pay_date = match ($subscription->package_type) {
                'lifetime' => null,
                'monthly' => Carbon::parse($invoice->pay_date)->addMonth(),
                default => Carbon::parse($invoice->pay_date)->addYear(),
            };
            $invoice->save();

            $emailSetting = EmailSetting::first();
            if ($emailSetting->mail_driver === 'smtp' && $emailSetting->verified) {
                $generatedBy = User::withoutGlobalScopes()->whereNull('branch_id')->whereNull('restaurant_id')->first();
                Notification::send($generatedBy, new RestaurantUpdatedPlan($restaurant, $subscription->package_id));
                $restaurantAdmin = $restaurant->restaurantAdmin($restaurant);
                Notification::send($restaurantAdmin, new RestaurantUpdatedPlan($restaurant, $subscription->package_id));
            }

            session()->forget('restaurant');
            request()->session()->flash('flash.banner', __('messages.planUpgraded'));
            request()->session()->flash('flash.bannerStyle', 'success');
            request()->session()->flash('flash.link', route('settings.index', ['tab' => 'billing']));

            return redirect()->route('dashboard')->with('livewire', true);
        } catch (\Exception $e) {
            logger(['error' => $e->getMessage()]);
            return redirect()->route('dashboard')->with([
                'flash.banner' => __('messages.paymentError'),
                'flash.bannerStyle' => 'danger'
            ]);
        }
    }
}
