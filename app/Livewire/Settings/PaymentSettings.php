<?php

namespace App\Livewire\Settings;

use App\Helper\Files;
use Livewire\Component;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Http;
use App\Models\PaymentGatewayCredential;
use App\Models\OfflinePaymentMethod;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class PaymentSettings extends Component
{

    use LivewireAlert, WithFileUploads;

    public $razorpaySecret;
    public $razorpayKey;
    public $razorpayStatus;
    public $isRazorpayEnabled;
    public $isStripeEnabled;
    public $offlinePaymentMethod;
    public $paymentGateway;
    public $stripeSecret;
    public $activePaymentSetting = null;
    public $stripeKey;
    public bool $stripeStatus;
    public $enableQrPayment = false;
    public $qrCodeImage;
    public $webhookUrl;
    public $flutterwaveMode;
    public $flutterwaveStatus;
    public $liveFlutterwaveKey;
    public $liveFlutterwaveSecret;
    public $liveFlutterwaveHash;
    public $testFlutterwaveKey;
    public $testFlutterwaveSecret;
    public $testFlutterwaveHash;
    public $testFlutterwaveWebhookKey;
    public $isFlutterwaveEnabled;
    public $isPaypalEnabled;
    public $paypalStatus;
    public $paypalMode;
    public $sandboxPaypalClientId;
    public $sandboxPaypalSecret;
    public $livePaypalClientId;
    public $livePaypalSecret;
    public $webhookRoute;
    public $payfastMerchantId;
    public $payfastMerchantKey;
    public $payfastPassphrase;
    public $payfastMode;
    public $payfastStatus;
    public $testPayfastMerchantId;
    public $testPayfastMerchantKey;
    public $testPayfastPassphrase;
    public $isPayfastEnabled;
    public bool $isGlobalRazorpayEnabled = false;
    public bool $isGlobalStripeEnabled = false;
    public bool $isGlobalFlutterwaveEnabled = false;
    public bool $isGlobalPaypalEnabled = false;
    public bool $isGlobalPayfastEnabled = false;
    public bool $isGlobalPaystackEnabled = false;
    public bool $isGlobalPaddleEnabled = false;
    public bool $isGlobalEpayEnabled = false;
    public bool $isGlobalMollieEnabled = false;
    public bool $isGlobalTapEnabled = false;
    public bool $isGlobalTlyncEnabled = false;

    public $paystackKey;
    public $paystackSecret;
    public $paystackMerchantEmail;
    public $paystackStatus;
    public $isPaystackEnabled;
    public $paystackMode;
    public $testPaystackKey;
    public $testPaystackSecret;
    public $testPaystackMerchantEmail;

    // Xendit properties
    public $xenditPublicKey;
    public $xenditSecretKey;
    public $xenditStatus;
    public $xenditMode;
    public $testXenditPublicKey;
    public $testXenditSecretKey;
    public $liveXenditPublicKey;
    public $liveXenditSecretKey;
    public $testXenditWebhookToken;
    public $liveXenditWebhookToken;
    public $isXenditEnabled;
    public bool $isGlobalXenditEnabled = false;

    // Epay properties
    public $epayStatus;
    public $epayMode;
    public $testEpayClientId;
    public $testEpayClientSecret;
    public $testEpayTerminalId;
    public $epayClientId;
    public $epayClientSecret;
    public $epayTerminalId;
    public $isEpayEnabled;

    // Mollie properties
    public $mollieStatus;
    public $mollieMode;
    public $testMollieKey;
    public $liveMollieKey;
    public $mollieWebhookSecret;
    public $isMollieEnabled;
    public $hasAnyOfflinePaymentEnabled = false;

    // Tap properties
    public $tapStatus;
    public $tapMode;
    public $tapMerchantId;
    public $liveTapSecretKey;
    public $liveTapPublicKey;
    public $testTapSecretKey;
    public $testTapPublicKey;
    public $isTapEnabled;

    // T-Lync properties
    public $tlyncStatus;
    public $tlyncMode;
    public $testTlyncStoreId;
    public $testTlyncStoreToken;
    public $liveTlyncStoreId;
    public $liveTlyncStoreToken;
    public $isTlyncEnabled;

    public function mount()
    {
         $settings = GlobalSetting::first();

        $this->isGlobalRazorpayEnabled = (bool) $settings->enable_razorpay;
        $this->isGlobalStripeEnabled = (bool) $settings->enable_stripe;
        $this->isGlobalFlutterwaveEnabled = (bool) $settings->enable_flutterwave;
        $this->isGlobalPaypalEnabled = (bool) $settings->enable_paypal;
        $this->isGlobalPayfastEnabled = (bool) $settings->enable_payfast;
        $this->isGlobalPaystackEnabled = (bool) $settings->enable_paystack;
        $this->isGlobalXenditEnabled = (bool) $settings->enable_xendit;
        $this->isGlobalPaddleEnabled = (bool) $settings->enable_paddle;
        $this->isGlobalEpayEnabled = (bool) $settings->enable_epay;
        $this->isGlobalMollieEnabled = (bool) $settings->enable_mollie;
        $this->isGlobalTapEnabled = (bool) ($settings->enable_tap ?? false);
        $this->isGlobalTlyncEnabled = (bool) ($settings->enable_tlync ?? false);
        $this->paymentGateway = PaymentGatewayCredential::first();

        $this->setDefaultActivePaymentSetting();

        $this->setCredentials();
    }


    private function setDefaultActivePaymentSetting()
    {
        if ($this->activePaymentSetting !== null) {
            return;
        }

        $paymentGateways = [
            'razorpay' => $this->isGlobalRazorpayEnabled,
            'stripe' => $this->isGlobalStripeEnabled,
            'flutterwave' => $this->isGlobalFlutterwaveEnabled,
            'paypal' => $this->isGlobalPaypalEnabled,
            'payfast' => $this->isGlobalPayfastEnabled,
            'paystack' => $this->isGlobalPaystackEnabled,
            'xendit' => $this->isGlobalXenditEnabled,
            'paddle' => $this->isGlobalPaddleEnabled,
            'epay' => $this->isGlobalEpayEnabled,
            'mollie' => $this->isGlobalMollieEnabled,
            'tap' => $this->isGlobalTapEnabled,
            'tlync' => $this->isGlobalTlyncEnabled,
            'offline' => true,
            'qr_code' => true,
        ];

        // Find the first enabled payment gateway
        foreach ($paymentGateways as $gateway => $isEnabled) {
            if ($isEnabled) {
                $this->activePaymentSetting = $gateway;
                break;
            }
        }

        if ($this->activePaymentSetting === null) {
            $this->activePaymentSetting = 'offline';
        }
    }

    public function activeSetting($tab)
    {
        $paymentGateways = [
            'razorpay' => $this->isGlobalRazorpayEnabled,
            'stripe' => $this->isGlobalStripeEnabled,
            'flutterwave' => $this->isGlobalFlutterwaveEnabled,
            'paypal' => $this->isGlobalPaypalEnabled,
            'payfast' => $this->isGlobalPayfastEnabled,
            'paystack' => $this->isGlobalPaystackEnabled,
            'xendit' => $this->isGlobalXenditEnabled,
            'paddle' => $this->isGlobalPaddleEnabled,
            'epay' => $this->isGlobalEpayEnabled,
            'mollie' => $this->isGlobalMollieEnabled,
            'tap' => $this->isGlobalTapEnabled,
            'tlync' => $this->isGlobalTlyncEnabled,
            'offline' => true,
            'qr_code' => true,
        ];

        if (isset($paymentGateways[$tab]) && $paymentGateways[$tab]) {
            $this->activePaymentSetting = $tab;
        } else {
            $this->activePaymentSetting = null;
            $this->setDefaultActivePaymentSetting();
        }

        $this->setCredentials();
    }

    private function setCredentials()
    {
        $this->razorpayKey = $this->paymentGateway->razorpay_key;
        $this->razorpaySecret = $this->paymentGateway->razorpay_secret;
        $this->razorpayStatus = (bool)$this->paymentGateway->razorpay_status;

        $this->stripeKey = $this->paymentGateway->stripe_key;
        $this->stripeSecret = $this->paymentGateway->stripe_secret;
        $this->stripeStatus = (bool)$this->paymentGateway->stripe_status;

        $this->isRazorpayEnabled = $this->paymentGateway->razorpay_status;
        $this->isStripeEnabled = $this->paymentGateway->stripe_status;
        $this->isFlutterwaveEnabled = $this->paymentGateway->flutterwave_status;

        $this->enableQrPayment = (bool)$this->paymentGateway->is_qr_payment_enabled;
        $this->qrCodeImage = $this->paymentGateway->qr_code_image_url;

        // Update offline payment status check - only check OfflinePaymentMethod table
        $restaurantId = restaurant() ? restaurant()->id : null;
        $this->hasAnyOfflinePaymentEnabled = OfflinePaymentMethod::where('restaurant_id', $restaurantId)
            ->where('status', 'active')
            ->exists();

        $this->flutterwaveMode = $this->paymentGateway->flutterwave_mode;
        $this->flutterwaveStatus = (bool)$this->paymentGateway->flutterwave_status;
        $this->liveFlutterwaveKey = $this->paymentGateway->live_flutterwave_key;
        $this->liveFlutterwaveSecret = $this->paymentGateway->live_flutterwave_secret;
        $this->liveFlutterwaveHash = $this->paymentGateway->live_flutterwave_hash;

        $this->testFlutterwaveKey = $this->paymentGateway->test_flutterwave_key;
        $this->testFlutterwaveSecret = $this->paymentGateway->test_flutterwave_secret;
        $this->testFlutterwaveHash = $this->paymentGateway->test_flutterwave_hash;

        $this->isPaypalEnabled = $this->paymentGateway->paypal_status;
        $this->paypalStatus = (bool) $this->paymentGateway->paypal_status;
        $this->paypalMode = $this->paymentGateway->paypal_mode;

        $this->sandboxPaypalClientId = $this->paymentGateway->sandbox_paypal_client_id;
        $this->sandboxPaypalSecret = $this->paymentGateway->sandbox_paypal_secret;
        $this->livePaypalClientId = $this->paymentGateway->paypal_client_id;
        $this->livePaypalSecret = $this->paymentGateway->paypal_secret;

        $this->payfastMerchantId = $this->paymentGateway->payfast_merchant_id;
        $this->payfastMerchantKey = $this->paymentGateway->payfast_merchant_key;
        $this->payfastPassphrase = $this->paymentGateway->payfast_passphrase;
        $this->payfastMode = $this->paymentGateway->payfast_mode;
        $this->payfastStatus = (bool)$this->paymentGateway->payfast_status;
        $this->testPayfastMerchantId = $this->paymentGateway->test_payfast_merchant_id;
        $this->testPayfastMerchantKey = $this->paymentGateway->test_payfast_merchant_key;
        $this->testPayfastPassphrase = $this->paymentGateway->test_payfast_passphrase;
        $this->isPayfastEnabled = $this->paymentGateway->payfast_status;

         $this->paystackStatus = (bool)$this->paymentGateway->paystack_status;
        $this->paystackKey = $this->paymentGateway->paystack_key;
        $this->paystackSecret = $this->paymentGateway->paystack_secret;
        $this->paystackMerchantEmail = $this->paymentGateway->paystack_merchant_email;
        $this->paystackMode = $this->paymentGateway->paystack_mode;
        $this->testPaystackKey = $this->paymentGateway->test_paystack_key;
        $this->testPaystackSecret = $this->paymentGateway->test_paystack_secret;
        $this->testPaystackMerchantEmail = $this->paymentGateway->test_paystack_merchant_email;
        $this->isPaystackEnabled = $this->paymentGateway->paystack_status;

        // Xendit credentials
        $this->xenditStatus = (bool)$this->paymentGateway->xendit_status;
        $this->xenditMode = in_array($this->paymentGateway->xendit_mode, ['live'], true) ? 'live' : 'sandbox';
        $this->testXenditPublicKey = $this->paymentGateway->test_xendit_public_key;
        $this->testXenditSecretKey = $this->paymentGateway->test_xendit_secret_key;
        $this->liveXenditPublicKey = $this->paymentGateway->live_xendit_public_key;
        $this->liveXenditSecretKey = $this->paymentGateway->live_xendit_secret_key;
        $this->testXenditWebhookToken = $this->paymentGateway->test_xendit_webhook_token;
        $this->liveXenditWebhookToken = $this->paymentGateway->live_xendit_webhook_token;
        $this->isXenditEnabled = $this->paymentGateway->xendit_status;

        // Epay credentials
        $this->epayStatus = (bool)$this->paymentGateway->epay_status;
        $this->epayMode = $this->paymentGateway->epay_mode;
        $this->testEpayClientId = $this->paymentGateway->test_epay_client_id;
        $this->testEpayClientSecret = $this->paymentGateway->test_epay_client_secret;
        $this->testEpayTerminalId = $this->paymentGateway->test_epay_terminal_id;
        $this->epayClientId = $this->paymentGateway->epay_client_id;
        $this->epayClientSecret = $this->paymentGateway->epay_client_secret;
        $this->epayTerminalId = $this->paymentGateway->epay_terminal_id;
        $this->isEpayEnabled = $this->paymentGateway->epay_status;

        // Mollie credentials
        $this->mollieStatus = (bool)$this->paymentGateway->mollie_status;
        $this->mollieMode = $this->paymentGateway->mollie_mode;
        $this->testMollieKey = $this->paymentGateway->test_mollie_key;
        $this->liveMollieKey = $this->paymentGateway->live_mollie_key;
        $this->mollieWebhookSecret = $this->paymentGateway->mollie_webhook_secret;
        $this->isMollieEnabled = $this->paymentGateway->mollie_status;

        // Tap credentials
        $this->tapStatus = (bool)($this->paymentGateway->tap_status ?? false);
        $this->tapMode = $this->paymentGateway->tap_mode ?? 'sandbox';
        $this->tapMerchantId = $this->paymentGateway->tap_merchant_id ?? null;
        $this->liveTapSecretKey = $this->paymentGateway->live_tap_secret_key ?? null;
        $this->liveTapPublicKey = $this->paymentGateway->live_tap_public_key ?? null;
        $this->testTapSecretKey = $this->paymentGateway->test_tap_secret_key ?? null;
        $this->testTapPublicKey = $this->paymentGateway->test_tap_public_key ?? null;
        $this->isTapEnabled = (bool)($this->paymentGateway->tap_status ?? false);

        $this->tlyncStatus = (bool)($this->paymentGateway->tlync_status ?? false);
        $this->tlyncMode = $this->paymentGateway->tlync_mode ?? 'test';
        $this->testTlyncStoreId = $this->paymentGateway->test_tlync_store_id ?? null;
        $this->testTlyncStoreToken = $this->paymentGateway->test_tlync_store_token ?? null;
        $this->liveTlyncStoreId = $this->paymentGateway->live_tlync_store_id ?? null;
        $this->liveTlyncStoreToken = $this->paymentGateway->live_tlync_store_token ?? null;
        $this->isTlyncEnabled = (bool)($this->paymentGateway->tlync_status ?? false);

        $hash = restaurant()->hash;
        $this->testFlutterwaveWebhookKey = $this->paymentGateway->flutterwave_webhook_secret_hash ? $this->paymentGateway->flutterwave_webhook_secret_hash : substr(md5($hash), 0, 10);

        if ($this->activePaymentSetting === 'flutterwave') {
            $this->webhookUrl = route('flutterwave.webhook', ['hash' => $hash]);
        }
        if ($this->activePaymentSetting === 'paypal') {
            $this->webhookUrl = route('paypal.webhook', ['hash' => $hash]);
        }
        if ($this->activePaymentSetting === 'paystack') {
            $this->webhookUrl = route('paystack.webhook', ['hash' => $hash]);
        }
        if ($this->activePaymentSetting === 'xendit') {
            $this->webhookUrl = route('xendit.webhook', ['hash' => $hash]);
        }
        if ($this->activePaymentSetting === 'mollie') {
            $this->webhookUrl = route('mollie.webhook', ['hash' => $hash]);
        }
        if ($this->activePaymentSetting === 'tap') {
            $this->webhookUrl = route('tap.webhook', ['hash' => $hash]);
        }
        if ($this->activePaymentSetting === 'tlync') {
            $this->webhookUrl = tlync_public_base_url()
                ? tlync_public_route('tlync.webhook', ['hash' => $hash])
                : route('tlync.webhook', ['hash' => $hash]);
        }
    }

    public function submitFormTlync()
    {
        if ($this->tlyncStatus) {
            $this->validate([
                'tlyncMode' => 'required|in:test,live',
                'liveTlyncStoreId' => 'required_if:tlyncMode,live',
                'liveTlyncStoreToken' => 'required_if:tlyncMode,live',
                'testTlyncStoreId' => 'required_if:tlyncMode,test',
                'testTlyncStoreToken' => 'required_if:tlyncMode,test',
            ]);
        }

        if ($this->saveTlyncSettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }
    }

    private function saveTlyncSettings()
    {
        if (!$this->tlyncStatus) {
            $this->paymentGateway->update([
                'tlync_status' => $this->tlyncStatus,
            ]);

            return 0;
        }

        $this->paymentGateway->update([
            'tlync_status' => $this->tlyncStatus,
            'tlync_mode' => $this->tlyncMode,
            'test_tlync_store_id' => $this->testTlyncStoreId,
            'test_tlync_store_token' => $this->testTlyncStoreToken,
            'live_tlync_store_id' => $this->liveTlyncStoreId,
            'live_tlync_store_token' => $this->liveTlyncStoreToken,
        ]);

        return 0;
    }

    public function submitFormTap()
    {
        if ($this->tapStatus) {
            $this->validate([
                'tapMode' => 'required',
                'tapMerchantId' => 'required',
                'liveTapSecretKey' => 'required_if:tapMode,live',
                'liveTapPublicKey' => 'required_if:tapMode,live',
                'testTapSecretKey' => 'required_if:tapMode,sandbox',
                'testTapPublicKey' => 'required_if:tapMode,sandbox',
            ]);
        }

        if ($this->saveTapSettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }

    }

    private function saveTapSettings()
    {
        if (!$this->tapStatus) {
            $this->paymentGateway->update([
                'tap_status' => $this->tapStatus,
            ]);
            return 0;
        }

        // Non-blocking validation: only treat 401/403 as invalid
        try {
            $secretKey = $this->tapMode === 'live' ? $this->liveTapSecretKey : $this->testTapSecretKey;
            $response = Http::withToken(trim((string)$secretKey))
                ->get('https://api.tap.company/v2/charges', ['limit' => 1]);

            if (in_array($response->status(), [401, 403], true)) {
                $field = $this->tapMode === 'live' ? 'liveTapSecretKey' : 'testTapSecretKey';
                $this->addError($field, 'Invalid Tap secret key.');
                return 1;
            }
        } catch (\Exception $e) {
            // Ignore connectivity errors; still allow saving
        }

        $this->paymentGateway->update([
            'tap_status' => $this->tapStatus,
            'tap_mode' => $this->tapMode,
            'tap_merchant_id' => $this->tapMerchantId,
            'live_tap_secret_key' => $this->liveTapSecretKey,
            'live_tap_public_key' => $this->liveTapPublicKey,
            'test_tap_secret_key' => $this->testTapSecretKey,
            'test_tap_public_key' => $this->testTapPublicKey,
        ]);

        return 0;
    }

    public function submitFormRazorpay()
    {
        $this->validate([
            'razorpaySecret' => 'required_if:razorpayStatus,true',
            'razorpayKey' => 'required_if:razorpayStatus,true',
        ]);

        if ($this->saveRazorpaySettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }
    }

    public function submitFormStripe()
    {
        $this->validate([
            'stripeSecret' => 'required_if:stripeStatus,true',
            'stripeKey' => 'required_if:stripeStatus,true',
        ]);

        if ($this->saveStripeSettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }
    }

    public function submitFormOffline()
    {
        $rules = [
            'enableQrPayment' => 'required|boolean'
        ];

        if ($this->enableQrPayment && !$this->paymentGateway->qr_code_image) {
            $rules['qrCodeImage'] = 'required|image|max:1024';
        }

        $this->validate($rules);

        // Upload QR code image if enabled and valid
        if ($this->enableQrPayment && is_object($this->qrCodeImage) && $this->qrCodeImage->isValid()) {
            $this->qrCodeImage = Files::uploadLocalOrS3(
                $this->qrCodeImage,
                PaymentGatewayCredential::QR_CODE_FOLDER,
                width: 800,
                oldFilename: $this->paymentGateway->qr_code_image
            );
        } else {
            $this->qrCodeImage = $this->paymentGateway->qr_code_image;
        }

        $updateData = [
            'is_qr_payment_enabled' => $this->enableQrPayment,
            'qr_code_image' => $this->qrCodeImage,
        ];

        $this->paymentGateway->update($updateData);

        $this->updatePaymentStatus();
        $this->alertSuccess();
    }

    private function saveRazorpaySettings()
    {
        if (!$this->razorpayStatus) {
            $this->paymentGateway->update([
                'razorpay_status' => $this->razorpayStatus,
            ]);
            return 0;
        }

        try {
            $response = Http::withBasicAuth($this->razorpayKey, $this->razorpaySecret)
                ->get('https://api.razorpay.com/v1/contacts');

            if ($response->successful()) {
                $this->paymentGateway->update([
                    'razorpay_key' => $this->razorpayKey,
                    'razorpay_secret' => $this->razorpaySecret,
                    'razorpay_status' => $this->razorpayStatus,
                ]);
                return 0;
            }

            $this->addError('razorpayKey', 'Invalid Razorpay key or secret.');
        } catch (\Exception $e) {
            $this->addError('razorpayKey', 'Error: ' . $e->getMessage());
        }

        return 1;
    }

    private function saveStripeSettings()
    {

        if (!$this->stripeStatus) {
            $this->paymentGateway->update([
                'stripe_status' => $this->stripeStatus,
            ]);
            return 0;
        }

        try {
            $response = Http::withToken($this->stripeSecret)
                ->get('https://api.stripe.com/v1/customers');

            if ($response->successful()) {
                $this->paymentGateway->update([
                    'stripe_key' => $this->stripeKey,
                    'stripe_secret' => $this->stripeSecret,
                    'stripe_status' => $this->stripeStatus,
                ]);
                return 0;
            }

            $this->addError('stripeKey', 'Invalid Stripe key or secret.');
        } catch (\Exception $e) {
            $this->addError('stripeKey', 'Error: ' . $e->getMessage());
        }

        return 1;
    }

    public function submitFlutterwaveForm()
    {

        $this->validate(
            [
                'flutterwaveStatus' => 'required|boolean',
                'flutterwaveMode' => 'required_if:flutterwaveStatus,true',
                'liveFlutterwaveKey' => 'required_if:flutterwaveMode,live',
                'liveFlutterwaveSecret' => 'required_if:flutterwaveMode,live',
                'liveFlutterwaveHash' => 'required_if:flutterwaveMode,live',
                'testFlutterwaveKey' => 'required_if:flutterwaveMode,test',
                'testFlutterwaveSecret' => 'required_if:flutterwaveMode,test',
                'testFlutterwaveHash' => 'required_if:flutterwaveMode,test',
            ],
            [
                'flutterwaveStatus.required' => __('validation.flutterwaveStatusRequired'),
                'flutterwaveMode.required_if' => __('validation.flutterwaveModeRequired'),
                'liveFlutterwaveKey.required_if' => __('validation.liveFlutterwaveKeyRequired'),
                'liveFlutterwaveSecret.required_if' => __('validation.liveFlutterwaveSecretRequired'),
                'liveFlutterwaveHash.required_if' => __('validation.liveFlutterwaveHashRequired'),
                'testFlutterwaveKey.required_if' => __('validation.testFlutterwaveKeyRequired'),
                'testFlutterwaveSecret.required_if' => __('validation.testFlutterwaveSecretRequired'),
                'testFlutterwaveHash.required_if' => __('validation.testFlutterwaveHashRequired'),
            ]
        );
        if ($this->saveFlutterwaveSettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }
    }

    private function saveFlutterwaveSettings()
    {

        if (!$this->flutterwaveStatus) {
            $this->paymentGateway->update([
                'flutterwave_status' => $this->flutterwaveStatus,
            ]);

            return 0;
        }

        try {
            $apiSecret = $this->flutterwaveMode === 'live' ? $this->liveFlutterwaveSecret : $this->testFlutterwaveSecret;

            $response = Http::withToken($apiSecret)
                ->get('https://api.flutterwave.com/v3/transactions');

            if ($response->successful()) {
                $this->paymentGateway->update([
                    'flutterwave_mode' => $this->flutterwaveMode,
                    'flutterwave_status' => $this->flutterwaveStatus,
                    'live_flutterwave_key' => $this->liveFlutterwaveKey,
                    'live_flutterwave_secret' => $this->liveFlutterwaveSecret,
                    'live_flutterwave_hash' => $this->liveFlutterwaveHash,
                    'test_flutterwave_key' => $this->testFlutterwaveKey,
                    'test_flutterwave_secret' => $this->testFlutterwaveSecret,
                    'test_flutterwave_hash' => $this->testFlutterwaveHash,
                    'flutterwave_webhook_secret_hash' => $this->testFlutterwaveWebhookKey,
                ]);
                return 0;
            }

            $this->addError(
                $this->flutterwaveMode === 'live' ? 'liveFlutterwaveKey' : 'testFlutterwaveKey',
                __('validation.InvalidFlutterwaveKeyOrSecret')
            );
        } catch (\Exception $e) {
            $this->addError('flutterwaveKey', 'Error: ' . $e->getMessage());
        }

        return 1;
    }
    public function submitFormPaypal()
    {
        $this->validate([
            'paypalStatus' => 'required|boolean',
            'paypalMode' => 'required_if:paypalStatus,true', // live or sandbox
            'livePaypalClientId' => 'required_if:paypalMode,live',
            'livePaypalSecret' => 'required_if:paypalMode,live',
            'sandboxPaypalClientId' => 'required_if:paypalMode,sandbox',
            'sandboxPaypalSecret' => 'required_if:paypalMode,sandbox',
        ]);

        if ($this->savePaypalSettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }

    }

    public function savePaypalSettings()
    {


        if (!$this->paypalStatus) {
            $this->paymentGateway->update([
                'paypal_status' => $this->paypalStatus,
            ]);
            return 0;
        }

        try {
            $apiKey = $this->paypalMode === 'live' ? $this->livePaypalClientId : $this->sandboxPaypalClientId;
            $apiSecret = $this->paypalMode === 'live' ? $this->livePaypalSecret : $this->sandboxPaypalSecret;

            $url = $this->paypalMode === 'live'
                ? 'https://api.paypal.com/v1/oauth2/token'
                : 'https://api.sandbox.paypal.com/v1/oauth2/token';

            $response = Http::withBasicAuth($apiKey, $apiSecret)
                ->asForm()
                ->post($url, [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->successful()) {
                $this->paymentGateway->update([
                    'paypal_mode' => $this->paypalMode,
                    'paypal_status' => $this->paypalStatus,
                    'sandbox_paypal_client_id' => $this->sandboxPaypalClientId,
                    'sandbox_paypal_secret' => $this->sandboxPaypalSecret,
                    'paypal_client_id' => $this->livePaypalClientId,
                    'paypal_secret' => $this->livePaypalSecret,
                ]);
                return 0;
            }

            $this->addError($this->paypalMode === 'live' ? 'livePaypalClientId' : 'sandboxPaypalClientId', 'Invalid Paypal key or secret.');
        } catch (\Exception $e) {
            $this->addError($this->paypalMode === 'live' ? 'livePaypalClientId' : 'sandboxPaypalClientId', 'Error: ' . $e->getMessage());
        }

        return 1;
    }

    public function submitFormPayfast()
    {
        $this->validate([
            'testPayfastMerchantId' => 'nullable|required_if:payfastMode,sandbox',
            'testPayfastMerchantKey' => 'nullable|required_if:payfastMode,sandbox',
            'payfastMerchantId' => 'nullable|required_if:payfastMode,live',
            'payfastMerchantKey' => 'nullable|required_if:payfastMode,live',
            'payfastPassphrase' => 'nullable|required_if:payfastMode,live',
        ]);

        if ($this->savePayfastSettings() === 0) {

            $this->updatePaymentStatus();
            $this->alertSuccess();
        }

    }

    private function savePayfastSettings()
    {

        if (!$this->payfastStatus) {
            $this->paymentGateway->update([
                'payfast_status' => $this->payfastStatus,
            ]);
            return 0;
        }

        try {
            $this->paymentGateway->update([
                'payfast_merchant_id' => $this->payfastMerchantId,
                'payfast_merchant_key' => $this->payfastMerchantKey,
                'payfast_passphrase' => $this->payfastPassphrase,
                'payfast_mode' => $this->payfastMode,
                'test_payfast_merchant_id' => $this->testPayfastMerchantId,
                'test_payfast_merchant_key' => $this->testPayfastMerchantKey,
                'test_payfast_passphrase' => $this->testPayfastPassphrase,
                'payfast_status' => $this->payfastStatus,
            ]);

            return 0;
        } catch (\Exception $e) {
            $this->addError('payfastKey', 'Error saving Payfast settings: ' . $e->getMessage());
            return 1;
        }
    }


    public function submitFormPaystack()
    {

        $this->validate([
            'testPaystackKey' => 'nullable|required_if:paystackMode,sandbox',
            'testPaystackSecret' => 'nullable|required_if:paystackMode,sandbox',
            'testPaystackMerchantEmail' => 'nullable|required_if:paystackMode,sandbox|email',

            'paystackKey' => 'nullable|required_if:paystackMode,live',
            'paystackSecret' => 'nullable|required_if:paystackMode,live',
            'paystackMerchantEmail' => 'nullable|required_if:paystackMode,live|email',
            ]);

        if ($this->savePaystackSettings() === 0) {

            $this->updatePaymentStatus();
            $this->alertSuccess();
        }

    }

    private function savePaystackSettings()
    {
        if (!$this->paystackStatus) {
            $this->paymentGateway->update([
                'paystack_status' => $this->paystackStatus,
            ]);
            return 0;
        }

        try {
            $apiSecret = $this->paystackMode === 'live' ? $this->paystackSecret : $this->testPaystackSecret;

            $response = Http::withToken($apiSecret)
                    ->get('https://api.paystack.co/transaction');
            if ($response->successful()) {
                $this->paymentGateway->update([
                    'paystack_key' => $this->paystackKey,
                    'paystack_secret' => $this->paystackSecret,
                    'paystack_merchant_email' => $this->paystackMerchantEmail,
                    'paystack_mode' => $this->paystackMode,
                    'test_paystack_key' => $this->testPaystackKey,
                    'test_paystack_secret' => $this->testPaystackSecret,
                    'test_paystack_merchant_email' => $this->testPaystackMerchantEmail,
                    'paystack_payment_url' => $this->paymentGateway->paystack_payment_url,
                    'paystack_status' => $this->paystackStatus,
                ]);
                return 0;
            }

            $this->addError('paystackKey', 'Invalid Paystack key or secret.');
        } catch (\Exception $e) {
            $this->addError('paystackKey', 'Error: ' . $e->getMessage());
        }

        return 1;
    }


    public function submitFormXendit()
    {
       if ($this->xenditStatus) {
            $this->validate([
                'testXenditPublicKey' => 'nullable|required_if:xenditMode,sandbox',
                'testXenditSecretKey' => 'nullable|required_if:xenditMode,sandbox',
                'liveXenditPublicKey' => 'nullable|required_if:xenditMode,live',
                'liveXenditSecretKey' => 'nullable|required_if:xenditMode,live',
            ]);
        }

        if ($this->saveXenditSettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }

    }

    private function saveXenditSettings()
    {
        if (!$this->xenditStatus) {
            $this->paymentGateway->update([
                'xendit_status' => $this->xenditStatus,
            ]);
            return 0;
        }

        try {
            $mode = $this->xenditMode === 'live' ? 'live' : 'sandbox';
            $secretKey = trim($mode === 'live' ? $this->liveXenditSecretKey : $this->testXenditSecretKey);
            $errorField = $mode === 'live' ? 'liveXenditSecretKey' : 'testXenditSecretKey';

            if ($secretKey === '') {
                $secretKey = trim($mode === 'live'
                    ? (string) $this->paymentGateway->live_xendit_secret_key
                    : (string) $this->paymentGateway->test_xendit_secret_key);
            }

            if ($secretKey === '') {
                $this->addError($errorField, __('modules.settings.xenditSecretRequired'));

                return 1;
            }

            if (str_starts_with($secretKey, 'xnd_public_')) {
                $this->addError($errorField, __('modules.settings.xenditUseSecretNotPublic'));

                return 1;
            }

            $balanceResponse = Http::timeout(20)
                ->withBasicAuth($secretKey, '')
                ->get('https://api.xendit.co/balance', ['account_type' => 'CASH']);

            $isValid = $balanceResponse->successful();

            if (! $isValid && $balanceResponse->status() !== 401) {
                $invoiceResponse = Http::timeout(20)
                    ->withBasicAuth($secretKey, '')
                    ->get('https://api.xendit.co/v2/invoices', ['limit' => 1]);

                $isValid = $invoiceResponse->status() !== 401
                    && ($invoiceResponse->successful() || in_array($invoiceResponse->status(), [403, 404], true));
            }

            if ($isValid) {
                $this->paymentGateway->update([
                    'xendit_mode' => $mode,
                    'test_xendit_public_key' => trim($this->testXenditPublicKey),
                    'test_xendit_secret_key' => trim($this->testXenditSecretKey) ?: $this->paymentGateway->test_xendit_secret_key,
                    'live_xendit_public_key' => trim($this->liveXenditPublicKey),
                    'live_xendit_secret_key' => trim($this->liveXenditSecretKey) ?: $this->paymentGateway->live_xendit_secret_key,
                    'test_xendit_webhook_token' => trim($this->testXenditWebhookToken) ?: $this->paymentGateway->test_xendit_webhook_token,
                    'live_xendit_webhook_token' => trim($this->liveXenditWebhookToken) ?: $this->paymentGateway->live_xendit_webhook_token,
                    'xendit_status' => $this->xenditStatus,
                ]);

                return 0;
            }

            $message = $balanceResponse->json('message');
            $this->addError(
                $errorField,
                is_string($message) && $message !== '' ? $message : __('modules.settings.xenditInvalidCredentials')
            );
        } catch (\Exception $e) {
            $field = $this->xenditMode === 'live' ? 'liveXenditSecretKey' : 'testXenditSecretKey';
            $this->addError($field, 'Error: ' . $e->getMessage());
        }

        return 1;
    }

    public function submitFormEpay()
    {
        if ($this->epayStatus) {
            $this->validate([
                'testEpayClientId' => 'nullable|required_if:epayMode,sandbox',
                'testEpayClientSecret' => 'nullable|required_if:epayMode,sandbox',
                'testEpayTerminalId' => 'nullable|required_if:epayMode,sandbox',
                'epayClientId' => 'nullable|required_if:epayMode,live',
                'epayClientSecret' => 'nullable|required_if:epayMode,live',
                'epayTerminalId' => 'nullable|required_if:epayMode,live',
            ]);
        }

        if ($this->saveEpaySettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }

    }

    private function saveEpaySettings()
    {
        if (!$this->epayStatus) {
            $this->paymentGateway->update([
                'epay_status' => $this->epayStatus,
            ]);
            return 0;
        }

        try {
            $clientId = $this->epayMode === 'live' ? $this->epayClientId : $this->testEpayClientId;
            $clientSecret = $this->epayMode === 'live' ? $this->epayClientSecret : $this->testEpayClientSecret;
            $terminalId = $this->epayMode === 'live' ? $this->epayTerminalId : $this->testEpayTerminalId;

            // Test ePay API connection using OAuth2 token endpoint
            $tokenUrl = $this->epayMode === 'live'
                ? 'https://epay-oauth.homebank.kz/oauth2/token'
                : 'https://testoauth.homebank.kz/epay2/oauth2/token';

            // Request token using form-data
            $response = Http::asForm()->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'scope' => 'webapi usermanagement email_send verification statement statistics payment',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'terminal' => $terminalId,
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                // Check if we received an access token
                if (isset($responseData['access_token']) || isset($responseData['token'])) {
                    $this->paymentGateway->update([
                        'epay_mode' => $this->epayMode,
                        'epay_status' => $this->epayStatus,
                        'test_epay_client_id' => $this->testEpayClientId,
                        'test_epay_client_secret' => $this->testEpayClientSecret,
                        'test_epay_terminal_id' => $this->testEpayTerminalId,
                        'epay_client_id' => $this->epayClientId,
                        'epay_client_secret' => $this->epayClientSecret,
                        'epay_terminal_id' => $this->epayTerminalId,
                    ]);
                    return 0;
                } else {
                    $field = $this->epayMode === 'live' ? 'epayClientId' : 'testEpayClientId';
                    $this->addError($field, 'Invalid ePay credentials. Token not received.');
                    return 1;
                }
            }

            // If response is not successful, extract error message
            $errorMessage = 'Invalid ePay credentials.';
            if ($response->json()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error_description'] ?? $errorData['error'] ?? $errorMessage;
            } elseif ($response->body()) {
                $errorMessage = $response->body();
            }

            $field = $this->epayMode === 'live' ? 'epayClientId' : 'testEpayClientId';
            $this->addError($field, $errorMessage);
            return 1;

        } catch (\Exception $e) {
            $field = $this->epayMode === 'live' ? 'epayClientId' : 'testEpayClientId';
            $this->addError($field, 'Error: ' . $e->getMessage());
        }

        return 1;
    }

    public function submitFormMollie()
    {
        $this->validate([
            'mollieStatus' => 'required|boolean',
            'mollieMode' => 'required_if:mollieStatus,true',
            'testMollieKey' => 'required_if:mollieMode,test',
            'liveMollieKey' => 'required_if:mollieMode,live',
        ]);

        if ($this->saveMollieSettings() === 0) {
            $this->updatePaymentStatus();
            $this->alertSuccess();
        }
    }

    private function saveMollieSettings()
    {
        if (!$this->mollieStatus) {
            $this->paymentGateway->update([
                'mollie_status' => $this->mollieStatus,
            ]);
            return 0;
        }

        try {
            // Get the appropriate API key based on mode
            $apiKey = $this->mollieMode === 'live' ? $this->liveMollieKey : $this->testMollieKey;

            // Test Mollie API connection
            $response = Http::withToken($apiKey)
                ->get('https://api.mollie.com/v2/methods');

            if ($response->successful()) {
                $this->paymentGateway->update([
                    'mollie_mode' => $this->mollieMode,
                    'mollie_status' => $this->mollieStatus,
                    'test_mollie_key' => $this->testMollieKey,
                    'live_mollie_key' => $this->liveMollieKey,
                    'mollie_webhook_secret' => $this->mollieWebhookSecret,
                ]);
                return 0;
            }

            $field = $this->mollieMode === 'live' ? 'liveMollieKey' : 'testMollieKey';
            $this->addError($field, 'Invalid Mollie API key.');
        } catch (\Exception $e) {
            $field = $this->mollieMode === 'live' ? 'liveMollieKey' : 'testMollieKey';
            $this->addError($field, 'Error: ' . $e->getMessage());
        }

        return 1;
    }

    public function updatePaymentStatus()
    {
        $this->setCredentials();
        $this->dispatch('settingsUpdated');
        session()->forget('paymentGateway');
    }

    public function updateOfflinePaymentStatus()
    {
        // Update offline payment status check - only check OfflinePaymentMethod table
        $restaurantId = restaurant() ? restaurant()->id : null;
        $this->hasAnyOfflinePaymentEnabled = OfflinePaymentMethod::where('restaurant_id', $restaurantId)->where('status', 'active')->exists();
    }

    public function alertSuccess()
    {
        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
    }

    protected $listeners = ['offlinePaymentMethodUpdated' => 'updateOfflinePaymentStatus'];

    public function render()
    {
        return view('livewire.settings.payment-settings');
    }
}
