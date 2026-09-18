<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TlyncPaymentService
{
    public function apiBaseUrl(string $mode = 'test'): string
    {
        $url = $mode === 'live'
            ? config('tlync.live_api_url')
            : config('tlync.test_api_url');

        return rtrim((string) $url, '/') . '/';
    }

    public function buildInitiatePayload(
        string $storeId,
        string $customRef,
        float|int|string $amount,
        string $phone,
        string $restaurantHash,
        int $tlyncPaymentId,
        ?string $email = null,
        ?string $shopName = null,
    ): array {
        // Keep frontend/cancel URLs clean — T-Lync appends payment data via POST (custom_ref, approved, etc.).
        // Query params with encoded pipes in custom_ref can break their redirect after Sadad checkout.
        $payload = [
            'id' => $storeId,
            'amount' => tlync_api_amount($amount),
            'phone' => tlync_api_phone($phone),
            'email' => $email ?: (string) config('tlync.default_email'),
            'backend_url' => tlync_public_route('tlync.webhook', ['hash' => $restaurantHash]),
            'frontend_url' => tlync_public_route('tlync.success'),
            'cancel_url' => tlync_public_route('tlync.cancel'),
            'custom_ref' => $customRef,
        ];

        if ($shopName) {
            $payload['shop_name'] = $shopName;
        }

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array{success: bool, url: ?string, data: array, error: ?string, status: int}
     */
    public function initiate(string $storeId, string $storeToken, string $mode, array $payload): array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->withToken($storeToken)
            ->post($this->apiBaseUrl($mode) . 'payment/initiate', $payload);

        $data = $response->json() ?? [];
        $checkoutUrl = $data['url'] ?? ($data['response']['url'] ?? null);
        $apiSuccess = ($data['result'] ?? null) === 'success';

        Log::info('T-Lync initiate response', [
            'api_url' => $this->apiBaseUrl($mode) . 'payment/initiate',
            'status' => $response->status(),
            'body' => $data,
        ]);

        if ($response->successful() && $apiSuccess && $checkoutUrl) {
            return [
                'success' => true,
                'url' => $checkoutUrl,
                'data' => $data,
                'error' => null,
                'status' => $response->status(),
            ];
        }

        return [
            'success' => false,
            'url' => null,
            'data' => is_array($data) ? $data : ['body' => $response->body()],
            'error' => $data['message'] ?? $data['error'] ?? __('messages.paymentInitiationFailedTryAgain'),
            'status' => $response->status(),
        ];
    }

    /**
     * POST /receipt/transaction — verify payment by custom_ref (TDSP docs).
     *
     * @return array{success: bool, complete: bool, data: array, status: int}
     */
    public function fetchReceipt(string $storeId, string $storeToken, string $mode, string $customRef): array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->withToken($storeToken)
            ->post($this->apiBaseUrl($mode) . 'receipt/transaction', [
                'store_id' => $storeId,
                'custom_ref' => $customRef,
            ]);

        $data = $response->json() ?? [];

        Log::info('T-Lync receipt lookup', [
            'custom_ref' => $customRef,
            'http_status' => $response->status(),
            'body' => $data,
        ]);

        return [
            'success' => $response->successful(),
            'complete' => $this->isReceiptComplete($data),
            'data' => is_array($data) ? $data : ['body' => $response->body()],
            'status' => $response->status(),
        ];
    }

    public function isApprovedCallback(array $data): bool
    {
        if (($data['approved'] ?? null) == 1 || ($data['approved'] ?? null) === '1' || ($data['approved'] ?? null) === true) {
            return true;
        }

        $result = strtolower((string) ($data['result'] ?? ''));
        if ($result === 'success') {
            return true;
        }

        $status = strtolower((string) ($data['status'] ?? $data['payment_status'] ?? ''));
        if (in_array($status, ['success', 'completed', 'paid', 'approved', '1', 'true'], true)) {
            return true;
        }

        if (($data['code'] ?? null) === 'ok' && ($data['reason'] ?? null) === 'success') {
            return true;
        }

        return false;
    }

    public function isReceiptComplete(array $data): bool
    {
        if (strtolower((string) ($data['result'] ?? '')) !== 'success') {
            return false;
        }

        $nestedStatus = strtolower((string) (
            $data['data']['notes_to_shop']['payment_status']
            ?? $data['data']['payment_status']
            ?? ''
        ));

        if (in_array($nestedStatus, ['success', 'completed', 'paid', 'approved'], true)) {
            return true;
        }

        return isset($data['data']) && is_array($data['data']) && !empty($data['data']);
    }

    public function transactionIdFromCallback(array $data): ?string
    {
        return $data['transaction_id']
            ?? $data['our_ref']
            ?? $data['gateway_ref']
            ?? $data['reference']
            ?? ($data['data']['gateway_ref'] ?? null)
            ?? null;
    }

    /**
     * Poll TDSP receipt API — Sadad/webhook can lag behind the browser redirect.
     */
    public function confirmWithReceiptRetries(
        string $storeId,
        string $storeToken,
        string $mode,
        string $customRef,
        int $attempts = 5,
        int $sleepMs = 1500
    ): ?array {
        for ($i = 0; $i < $attempts; $i++) {
            $receipt = $this->fetchReceipt($storeId, $storeToken, $mode, $customRef);

            if ($receipt['complete']) {
                return $receipt['data'];
            }

            if ($i < $attempts - 1) {
                usleep($sleepMs * 1000);
            }
        }

        return null;
    }
}
