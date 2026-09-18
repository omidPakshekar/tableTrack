<?php

namespace App\Http\Controllers;

use App\Models\DeliveryExecutive;
use App\Models\Otp;
use App\Notifications\SendOtp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class DeliveryExecutiveAuthController extends Controller
{
    private function isDemoDeliveryOtpMode(): bool
    {
        return app()->environment('demo') || (bool) config('app.demo_delivery_otp', false);
    }

    private function issueDeliveryOtp(string $email): string
    {
        $otp = $this->isDemoDeliveryOtpMode()
            ? '123456'
            : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Otp::updateOrCreate(
            ['identifier' => $email, 'type' => 'delivery_login'],
            [
                'token' => $otp,
                'expires_at' => Carbon::now()->addMinutes(10),
                'used' => false,
            ]
        );

        if (! $this->isDemoDeliveryOtpMode()) {
            Notification::route('mail', $email)->notify(new SendOtp($otp));
        }

        return $otp;
    }

    public function showOtpLoginForm()
    {
        if (delivery_executive()) {
            return redirect()->route('delivery.assigned-orders');
        }

        return view('delivery-portal.auth.otp-login');
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);

        $executive = DeliveryExecutive::where('email', $email)->first();

        if (!$executive) {
            return back()->withErrors(['email' => __('messages.noUserFoundWithThisEmailAddress')]);
        }

        if ($executive->status === 'inactive') {
            return back()->withErrors(['email' => __('auth.failed')]);
        }

        $otp = $this->issueDeliveryOtp($email);
        $statusMessage = $this->isDemoDeliveryOtpMode()
            ? 'Demo OTP is 123456.'
            : __('messages.otpSentToYourEmailAddress');

        return back()->with('status', $statusMessage)
            ->with('otp_sent', true)
            ->with('email', $email)
            ->with('demo_otp', $this->isDemoDeliveryOtpMode() ? $otp : null);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp_combined' => 'required|string|size:6',
        ]);

        $email = strtolower($request->email);

        $otpRecord = Otp::where('identifier', $email)
            ->where('token', $request->otp_combined)
            ->where('type', 'delivery_login')
            ->where('used', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otpRecord) {
            return back()->withErrors(['otp' => __('messages.invalidOrExpiredOtp')])
                ->with('otp_sent', true)
                ->with('email', $email);
        }

        $executive = DeliveryExecutive::with('branch.restaurant.currency')->where('email', $email)->first();

        if (!$executive || $executive->status === 'inactive') {
            return back()->withErrors(['email' => __('auth.failed')])
                ->with('otp_sent', true)
                ->with('email', $email);
        }

        $otpRecord->update(['used' => true]);
        $executive->update(['is_online' => true]);

        $request->session()->regenerate();
        session([
            'delivery_executive_id' => $executive->id,
            'delivery_executive' => $executive,
            'shop_branch' => $executive->branch,
            'restaurant' => $executive->branch?->restaurant,
        ]);

        return redirect()->intended(route('delivery.dashboard'));
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);
        $executive = DeliveryExecutive::where('email', $email)->first();

        if (!$executive || $executive->status === 'inactive') {
            return response()->json([
                'success' => false,
                'message' => __('messages.userNotFound'),
            ], 404);
        }

        $this->issueDeliveryOtp($email);

        return response()->json([
            'success' => true,
            'message' => __('messages.otpResentSuccessfully'),
        ]);
    }

    public function logout(Request $request)
    {
        $executive = delivery_executive();
        if ($executive) {
            $executive->update(['is_online' => false]);
        }

        session()->forget(['delivery_executive_id', 'delivery_executive', 'restaurant', 'shop_branch']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('delivery.login');
    }
}
