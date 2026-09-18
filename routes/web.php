<?php

use App\Exports\PaymentExport;
use App\Livewire\CustomerDisplay;
use App\Http\Middleware\SuperAdmin;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KotController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ShopController;
use App\Http\Middleware\DisableFrontend;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TableController;
use App\Http\Middleware\LocaleMiddleware;
use App\Http\Controllers\PosApiController;
use App\Http\Controllers\QRCodeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RecurringExpenseController;
use App\Http\Controllers\PosAjaxController;
use App\Http\Controllers\ViewPngController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\OtpLoginController;
use App\Http\Controllers\DeliveryPortalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryExecutiveAuthController;
use App\Http\Controllers\CustomMenuController;
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\RestaurantImpersonationLogController;
use App\Http\Controllers\TapPaymentController;
use App\Http\Controllers\TlyncPaymentController;
use App\Http\Controllers\EpayPaymentController;
use App\Http\Controllers\LandingSiteController;
use App\Http\Controllers\ReservationController;
use App\Http\Middleware\CheckRestaurantPackage;
use App\Http\Middleware\CustomerSiteMiddleware;
use App\Http\Middleware\VerifyRestaurantAccess;
use App\Http\Controllers\CustomModuleController;
use App\Http\Controllers\ItemCategoryController;
use App\Http\Controllers\ItemModifierController;
use App\Http\Controllers\GlobalSettingController;
use App\Http\Controllers\ModifierGroupController;
use App\Http\Controllers\MolliePaymentController;
use App\Http\Controllers\PaypalPaymentController;
use App\Http\Controllers\WaiterRequestController;
use App\Http\Controllers\XenditPaymentController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\OnboardingStepController;
use App\Http\Controllers\PayfastPaymentController;
use App\Http\Controllers\SuperAdmin\TapController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\DeliveryExecutiveController;
use App\Http\Controllers\RestaurantPaymentController;
use App\Http\Controllers\RestaurantSettingController;
use App\Http\Controllers\SuperAdmin\MollieController;
use App\Http\Controllers\SuperAdmin\PaypalController;
use App\Http\Controllers\SuperAdmin\XenditController;
use App\Http\Controllers\SuperadminSettingController;
use App\Http\Controllers\FlutterwavePaymentController;
use App\Http\Controllers\SuperAdmin\PayfastController;
use App\Http\Controllers\SuperAdmin\PaystackController;
use App\Http\Controllers\SuperAdmin\FlutterwaveController;
use App\Http\Controllers\SuperAdmin\StripeWebhookController;
use App\Http\Controllers\SuperAdmin\XenditWebhookController;
use App\Http\Controllers\SuperAdmin\PayFastWebhookController;
use App\Http\Controllers\SuperAdmin\PaystackWebhookController;
use App\Http\Controllers\SuperAdmin\RazorpayWebhookController;
use App\Http\Controllers\SuperAdmin\FlutterwaveWebhookController;
use App\Http\Middleware\EnsureDeliveryExecutiveAuthenticated;

Route::get('/manifest.json', [HomeController::class, 'manifest'])->name('manifest');

// Signed URL endpoint used in emails to download Menu PDF without requiring a logged-in session
Route::get('/menus/pdf/{restaurant}', [MenuController::class, 'downloadPdf'])
    ->name('menus.pdf')
    ->where('restaurant', '[0-9]+')
    ->middleware('signed');

Route::get('/qr/table/{hash}', [ShopController::class, 'qrTableLanding'])->name('qr_table_landing');

Route::group(['prefix' => 'restaurant'], function () {
    Route::get('/table/{hash}', [ShopController::class, 'tableOrder'])->name('table_order')->where('id', '.*');
    if (function_exists('module_enabled') && module_enabled('Hotel')) {
        Route::get('/room/{hash}', [ShopController::class, 'roomOrder'])->name('room_order')->where('id', '.*');
    }
    Route::get('/my-orders/{hash}', [ShopController::class, 'myOrders'])->name('my_orders');
    Route::get('/my-bookings/{hash}', [ShopController::class, 'myBookings'])->name('my_bookings');
    Route::get('/my-addresses/{hash}',  [ShopController::class, 'myAddresses'])->name('my_addresses');
    Route::get('/book-a-table/{hash}', [ShopController::class, 'bookTable'])->name('book_a_table');
    Route::get('/contact/{hash}', [ShopController::class, 'contact'])->name('contact');
    Route::get('/about-us/{hash}', [ShopController::class, 'about'])->name('about');
    Route::get('/profile/{hash}', [ShopController::class, 'profile'])->name('profile');
    Route::get('/orders-success/{id}', [ShopController::class, 'orderSuccess'])->name('order_success');
});

Route::get('/restaurant/{hash}', [ShopController::class, 'cart'])->name('shop_restaurant');
Route::post('/restaurant/{hash}/browse-order-type', [ShopController::class, 'syncBrowseOrderType'])
    ->name('shop.sync_browse_order_type');
Route::post('/restaurant/{hash}/browse-cart-mutate', [ShopController::class, 'browseCartMutate'])
    ->name('shop.browse_cart_mutate');


// Only register the root route if Subdomain module is not enabled
if (!function_exists('module_enabled') || !module_enabled('Subdomain')) {
    Route::get('/', [HomeController::class, 'landing'])->name('home')->middleware(DisableFrontend::class);
    Route::get('/change-locale/{locale}', [HomeController::class, 'changeLocale'])->name('change.locale');
}

Route::get('/restaurant-signup', [HomeController::class, 'signup'])->name('restaurant_signup');
Route::get('/customer-logout', [HomeController::class, 'customerLogout'])->name('customer_logout');
Route::get('page/{slug}', [CustomMenuController::class, 'index'])->name('customMenu');



Route::post('stripe/order-payment', [StripeController::class, 'orderPayment'])->name('stripe.order_payment');
Route::post('stripe/order-embedded-setup', [StripeController::class, 'orderEmbeddedSetup'])->name('stripe.order_embedded_setup');
Route::get('stripe/order-embedded-return', [StripeController::class, 'orderEmbeddedReturn'])->name('stripe.order_embedded_return');
Route::get('/stripe/success-callback', [StripeController::class, 'success'])->name('stripe.success');

Route::post('stripe/license-payment', [StripeController::class, 'licensePayment'])->name('stripe.license_payment');
Route::get('/stripe/license-success-callback', [StripeController::class, 'licenseSuccess'])->name('stripe.license_success');
Route::post('/flutterwave/initiate-payment', [FlutterwaveController::class, 'initiatePayment'])->name('flutterwave.initiate-payment');
Route::get('/flutterwave/callback', [FlutterwaveController::class, 'paymentCallback'])->name('flutterwave.callback');

// OTP Login Routes
Route::get('/otp-login', [OtpLoginController::class, 'showOtpLoginForm'])->name('otp.login');
Route::post('/otp/send', [OtpLoginController::class, 'sendOtp'])->name('otp.send');
Route::post('/otp/verify', [OtpLoginController::class, 'verifyOtp'])->name('otp.verify');
Route::post('/otp/resend', [OtpLoginController::class, 'resendOtp'])->name('otp.resend');

Route::prefix('delivery')->name('delivery.')->group(function () {
    Route::get('/login', [DeliveryExecutiveAuthController::class, 'showOtpLoginForm'])->name('login');
    Route::post('/otp/send', [DeliveryExecutiveAuthController::class, 'sendOtp'])->name('otp.send');
    Route::post('/otp/verify', [DeliveryExecutiveAuthController::class, 'verifyOtp'])->name('otp.verify');
    Route::post('/otp/resend', [DeliveryExecutiveAuthController::class, 'resendOtp'])->name('otp.resend');

    Route::middleware(EnsureDeliveryExecutiveAuthenticated::class)->group(function () {
        Route::get('/dashboard', [DeliveryPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/assigned-orders', [DeliveryPortalController::class, 'assignedOrders'])->name('assigned-orders');
        Route::get('/orders/{uuid}', [DeliveryPortalController::class, 'orderDetails'])->name('orders.show');
        Route::get('/history', [DeliveryPortalController::class, 'history'])->name('history');
        Route::get('/cod-settlement', [DeliveryPortalController::class, 'codSettlement'])->name('cod-settlement');
        Route::get('/profile', [DeliveryPortalController::class, 'profile'])->name('profile');
        Route::get('/logout', [DeliveryExecutiveAuthController::class, 'logout'])->name('logout');
    });
});

Route::post('/paypal/initiate-payment', [PaypalController::class, 'initiatePayment'])->name('paypal.initiate-payment');
Route::get('billing/paypal-recurring', [PaypalController::class, 'payWithPaypalRecurrring'])->name('billing.paypal-recurring');
Route::get('/paypal/lifetime/success', [PaypalController::class, 'paypalLifetimeSuccess'])->name('paypal.lifetime.success');

Route::post('/payfast/initiate-payment', [PayfastController::class, 'initiatePayfastPayment'])->name('payfast.initiate-payment');
Route::get('billing/payfast-success', [PayFastController::class, 'payFastPaymentSuccess'])->name('billing.payfast-success');
Route::get('billing/payfast-cancel', [PayFastController::class, 'payFastPaymentCancel'])->name('billing.payfast-cancel');

Route::post('/paystack/initiate-payment', [PaystackController::class, 'initiatePaystackPayment'])->name('paystack.initiate-payment');
Route::post('/xendit/initiate-payment', [XenditController::class, 'initiatePaystackPayment'])->name('xendit.initiate-payment');

Route::get('/paystack/callback', [PaystackController::class, 'handleGatewayCallback'])->name('paystack.callback');



Route::middleware(['auth', config('jetstream.auth_session'), 'verified', VerifyRestaurantAccess::class, CheckRestaurantPackage::class])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('account_unverified', [DashboardController::class, 'accountUnverified'])->name('account_unverified');

    Route::get('onboarding-steps', [OnboardingStepController::class, 'index'])->name('onboarding_steps');

    Route::resource('menus', MenuController::class);
    Route::get('menu-items/sort-entities', [MenuController::class, 'unifiedSort'])->name('menu-items.entities.sort');
    Route::get('menu-items/bulk-import', [MenuItemController::class, 'bulkImport'])->name('menu-items.bulk-import');
    Route::get('menu-items/export', [MenuItemController::class, 'exportPage'])->name('menu-items.export');
    Route::get('menu-items/export/download', [MenuItemController::class, 'downloadExport'])->name('menu-items.export.download');
    Route::get('menu-items/export/direct', [MenuItemController::class, 'exportDirect'])->name('menu-items.export.direct');
    Route::resource('menu-items', MenuItemController::class);
    Route::resource('item-categories', ItemCategoryController::class);
    Route::resource('item-modifiers', ItemModifierController::class);
    Route::resource('modifier-groups', ModifierGroupController::class);

    Route::resource('areas', AreaController::class);
    Route::resource('tables', TableController::class);

    Route::get('orders/print/{id}', [OrderController::class, 'printOrder'])->name('orders.print');
    Route::get('orders/pdf/{id}', [OrderController::class, 'generateOrderPdf'])->name('orders.pdf');
    Route::post('orders/{uuid}/waiter/accept', [OrderController::class, 'waiterAccept'])->name('orders.waiter.accept');
    Route::post('orders/{uuid}/waiter/decline', [OrderController::class, 'waiterDecline'])->name('orders.waiter.decline');
    Route::post('orders/{uuid}/waiter/status', [OrderController::class, 'updateWaiterResponse'])->name('orders.waiter.status');
    Route::get('orders/recent', [OrderController::class, 'recent'])->name('orders.recent');
    Route::get('orders/print-split/{orderId}', [OrderController::class, 'printSplitOrder'])->name('orders.print-split');
    Route::get('orders/print-split-receipts/{orderId}', [\App\Http\Controllers\SplitPaymentReceiptController::class, 'printSplitReceipts'])->name('orders.print-split-receipts');
    Route::resource('orders', OrderController::class);

    // POS routes with machine tracking middleware (registered in module service provider)
    // Only apply middleware if MultiPOS module is enabled
    $posMiddleware = (function_exists('module_enabled') && module_enabled('MultiPOS'))
        ? ['pos.machine']
        : [];

    Route::middleware($posMiddleware)->group(function () {
        Route::get('pos/order/{id}', [PosController::class, 'order'])->name('pos.order');
        Route::get('pos/kot/{id}', [PosController::class, 'kot'])->name('pos.kot');
        Route::get('pos/draft/{id}', [PosController::class, 'draft'])->name('pos.draft');
        Route::resource('pos', PosController::class);
    });

    Route::resource('kots', KotController::class);
    Route::get('kot/print/{id}/{kotPlaceid?}', [KotController::class, 'printkot'])->name('kot.print');

    Route::resource('customers', CustomerController::class);

    Route::resource('settings', RestaurantSettingController::class);


    Route::get('payments/export', fn() => Excel::download(new PaymentExport, 'payments-' . now()->toDateTimeString() . '.xlsx'))->name('payments.export');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/due', [PaymentController::class, 'due'])->name('payments.due');
    Route::get('payments/expenses', [PaymentController::class, 'expenses'])->name('payments.expenses');
    Route::get('payments/recurring-expenses', [RecurringExpenseController::class, 'index'])->name('payments.recurring-expenses');
    Route::get('payments/expenseCategory', [PaymentController::class, 'expenseCategory'])->name('payments.expenseCategory');

    Route::get('qr-codes', [QRCodeController::class, 'index'])->name('qrcodes.index');

    Route::resource('reservations', ReservationController::class);

    Route::prefix('reports')->group(function () {
        Route::get('item-report', [ReportController::class, 'itemReport'])->name('reports.item');
        Route::get('category-report', [ReportController::class, 'categoryReport'])->name('reports.category');
        Route::get('sales-report', [ReportController::class, 'salesReport'])->name('reports.sales');
        Route::get('expense-report', [ReportController::class, 'expenseReport'])->name('reports.expenseReports');
        Route::get('outstanding-payment-report', [ReportController::class, 'outstandingPaymentReport'])->name('reports.outstandingPayment');
        Route::get('due-payment-received-report', [ReportController::class, 'duePaymentReceivedReport'])->name('reports.duePaymentReceived');
        Route::get('expense-summary-report', [ReportController::class, 'expenseSummaryReport'])->name('reports.expensesummaryreport');
        Route::get('print-log', [ReportController::class, 'printLog'])->name('reports.printLog');
        Route::get('delivery-report', [ReportController::class, 'deliveryReport'])->name('reports.delivery');
        Route::get('cod-report', [ReportController::class, 'codReport'])->name('reports.cod');
        Route::get('cancelled-order-report', [ReportController::class, 'cancelledOrderReport'])->name('reports.cancelledOrder');
        Route::get('removed-kot-item-report', [ReportController::class, 'removedKotItemReport'])->name('reports.removedKotItem');
        Route::get('deleted-order-report', [ReportController::class, 'deletedOrderReport'])->name('reports.deletedOrder');
        Route::get('tax-report', [ReportController::class, 'taxReport'])->name('reports.tax');
        Route::get('refund-report', [ReportController::class, 'refundReport'])->name('reports.refund');
        Route::get('order-report', [ReportController::class, 'orderReport'])->name('reports.orderReport');
    });

    Route::resource('staff', StaffController::class);

    Route::get('delivery-executives/cash-monitoring', [DeliveryExecutiveController::class, 'cashMonitoring'])
        ->name('delivery-executives.cash-monitoring');
    Route::resource('delivery-executives', DeliveryExecutiveController::class);
    Route::get('delivery-executives/{delivery_executive}/orders/{order}/tracking', [DeliveryExecutiveController::class, 'trackingData'])
        ->name('delivery-executives.tracking-data');
    Route::get('billing/upgrade-plan', [PlanController::class, 'index'])->name('pricing.plan');

    Route::post('stripe/license-embedded-setup', [StripeController::class, 'licenseEmbeddedSetup'])->name('stripe.license_embedded_setup');
    Route::get('stripe/license-embedded-return', [StripeController::class, 'licenseEmbeddedReturn'])->name('stripe.license_embedded_return');

    Route::get('/pusher/beams-auth', [DashboardController::class, 'beamAuth'])->name('beam_auth');
    Route::post('/subscribe', [PushNotificationController::class, 'subscribe']);

    Route::resource('waiter-requests', WaiterRequestController::class);

    Route::get('/customer-display', [\App\Http\Controllers\PosController::class, 'customerDisplay'])->name('customer.display');
    Route::get('/customer-order-board', [\App\Http\Controllers\PosController::class, 'customerOrderBoard'])->name('customer.order-board');
});

Route::middleware(['auth', config('jetstream.auth_session'), 'verified', SuperAdmin::class])->group(function () {

    Route::name('superadmin.')->group(function () {
        Route::get('super-admin-dashboard', [DashboardController::class, 'superadmin'])->name('dashboard');

        Route::resource('restaurants', RestaurantController::class);

        Route::get('impersonation-logs', [RestaurantImpersonationLogController::class, 'index'])->name('impersonation-logs.index');

        Route::resource('restaurant-payments', RestaurantPaymentController::class);

        Route::resource('packages', PackageController::class);

        Route::resource('invoices', BillingController::class);


        Route::get('offline-plan', [BillingController::class, 'offlinePlanRequests'])->name('offline-plan-request');

        Route::get('users', [SuperadminSettingController::class, 'users'])->name('users.index');

        Route::resource('superadmin-settings', SuperadminSettingController::class);

        Route::post('app-update/deleteFile', [GlobalSettingController::class, 'deleteFile'])->name('app-update.deleteFile');
        Route::resource('app-update', GlobalSettingController::class);
        Route::post('custom-modules/verify-purchase', [CustomModuleController::class, 'verifyingModulePurchase'])->name('custom-modules.verify_purchase');
        Route::resource('custom-modules', CustomModuleController::class)->except(['update']);
        Route::put('custom-modules/{custom_module}', [CustomModuleController::class, 'update'])->withoutMiddleware('csrf')->name('custom-modules.update');

        Route::resource('landing-sites', LandingSiteController::class);
    });
});

Route::post('/webhook/billing-verify-webhook/{hash?}', [StripeWebhookController::class, 'verifyStripeWebhook'])->name('billing.verify-webhook');
Route::post('/webhook/save-razorpay-webhook/{hash?}', [RazorpayWebhookController::class, 'saveInvoices'])->name('billing.save_razorpay-webhook');
Route::post('/webhook/flutter-webhook/{hash}', [FlutterwavePaymentController::class, 'handleGatewayWebhook'])->name('flutterwave.webhook');
Route::match(['get', 'post'], '/flutterwave/success', [FlutterwavePaymentController::class, 'paymentMainSuccess'])->name('flutterwave.success');
Route::match(['get', 'post'], '/flutterwave/failed', [FlutterwavePaymentController::class, 'paymentFailed'])->name('flutterwave.failed');
Route::post('/webhook/save-flutterwave-webhook/{hash}', [FlutterwaveWebhookController::class, 'handleWebhook'])->name('billing.save-flutterwave-webhook');
Route::post('save-paypal-webhook/{hash}', [PaypalController::class, 'verifyBillingIPN'])->name('billing.save_paypal-webhook');
Route::post('payfast-notification/{id}', [PayFastWebhookController::class, 'saveInvoice'])->name('payfast-notification');
Route::post('/webhook/save-paystack-webhook/{hash}', [PaystackWebhookController::class, 'saveInvoices'])->name('billing.save-paystack-webhook');
Route::view('offline', 'offline');

Route::match(['get', 'post'], '/payfast/success', [PayfastPaymentController::class, 'paymentMainSuccess'])->name('payfast.success');
Route::match(['get', 'post'], '/payfast/failed', [PayfastPaymentController::class, 'paymentFailed'])->name('payfast.failed');
Route::post('/webhook/notify/{company}/{reference}', [PayfastPaymentController::class, 'payfastNotify'])->name('payfast.notify');

Route::post('/webhook/paypal-webhook/{hash}', [PaypalPaymentController::class, 'handleGatewayWebhook'])->name('paypal.webhook');
Route::get('paypal/success', [PaypalPaymentController::class, 'success'])->name('paypal.success');
Route::get('paypal/cancel', [PaypalPaymentController::class, 'cancel'])->name('paypal.cancel');

// Paddle Subscription Routes
Route::post('/paddle/subscription/initiate', [\App\Http\Controllers\SuperAdmin\PaddleController::class, 'initiatePaddlePayment'])->name('paddle.subscription.initiate');
Route::get('/paddle/checkout', [\App\Http\Controllers\SuperAdmin\PaddleController::class, 'showCheckoutPage'])->name('paddle.checkout.page');
Route::match(['get', 'post'], '/paddle/subscription/callback', [\App\Http\Controllers\SuperAdmin\PaddleController::class, 'handleGatewayCallback'])->name('paddle.subscription.callback');
Route::match(['get', 'post'], '/paddle/subscription/failed', [\App\Http\Controllers\SuperAdmin\PaddleController::class, 'paymentFailed'])->name('paddle.subscription.failed');

// Paddle Webhook Routes
Route::post('/webhook/save-paddle-webhook/{hash}', [\App\Http\Controllers\SuperAdmin\PaddleWebhookController::class, 'handleWebhook'])->name('billing.save-paddle-webhook');
Route::match(['get', 'post'], '/paystack/success', [PaystackPaymentController::class, 'paymentMainSuccess'])->name('paystack.success');
Route::post('/webhook/paystack-webhook/{hash}', [PaystackPaymentController::class, 'handleGatewayWebhook'])->name('paystack.webhook');
Route::match(['get', 'post'], '/paystack/failed', [PaystackPaymentController::class, 'paymentFailed'])->name('paystack.failed');

Route::post('/webhook/xendit-webhook/{hash}', [XenditPaymentController::class, 'handleGatewayWebhook'])->name('xendit.webhook');
Route::match(['get', 'post'], '/xendit/success', [XenditPaymentController::class, 'paymentMainSuccess'])->name('xendit.success');
Route::match(['get', 'post'], '/xendit/failed', [XenditPaymentController::class, 'paymentFailed'])->name('xendit.failed');

// Xendit Subscription Routes
Route::post("/xendit/subscription/initiate", [XenditController::class, "initiateXenditPayment"])->name("xendit.subscription.initiate");
Route::match(["get", "post"], "/xendit/subscription/callback", [XenditController::class, "handleGatewayCallback"])->name("xendit.subscription.callback");
Route::match(["get", "post"], "/xendit/subscription/failed", [XenditController::class, "paymentFailed"])->name("xendit.subscription.failed");

// Xendit Webhook Routes
Route::post('/webhook/save-xendit-webhook/{hash}', [XenditWebhookController::class, 'handleSubscriptionWebhook'])->name('billing.save-xendit-webhook');

// Tap Plan Payment Routes
Route::post('/tap/initiate-payment', [TapController::class, 'initiatePayment'])->name('tap.initiate-payment');
Route::get('/tap/plan/success', [TapController::class, 'paymentSuccess'])->name('tap.plan.success');
Route::post('/tap/plan/webhook', [TapController::class, 'handleWebhook'])->name('tap.plan.webhook');

// Epay Payment Routes
Route::match(['get', 'post'], '/epay/success', [EpayPaymentController::class, 'success'])->name('epay.success');
Route::match(['get', 'post'], '/epay/cancel', [EpayPaymentController::class, 'cancel'])->name('epay.cancel');
Route::post('/epay/webhook/{hash}', [EpayPaymentController::class, 'webhook'])->name('epay.webhook');

// Mollie Plan Payment Routes
Route::post('/mollie/initiate-payment', [MollieController::class, 'initiatePayment'])->name('mollie.initiate-payment');
Route::get('/mollie/plan/success', [MollieController::class, 'paymentSuccess'])->name('mollie.plan.success');
Route::post('/mollie/plan/webhook', [MollieController::class, 'handleWebhook'])->name('mollie.plan.webhook');

Route::match(['get', 'post'], '/tap/success', [TapPaymentController::class, 'success'])->name('tap.success');
Route::match(['get', 'post'], '/tap/cancel', [TapPaymentController::class, 'cancel'])->name('tap.cancel');
Route::post('/webhook/tap-webhook/{hash}', [TapPaymentController::class, 'webhook'])->name('tap.webhook');

Route::match(['get', 'post'], '/tlync/success', [TlyncPaymentController::class, 'success'])->name('tlync.success');
Route::match(['get', 'post'], '/tlync/cancel', [TlyncPaymentController::class, 'cancel'])->name('tlync.cancel');
Route::match(['get', 'post'], '/webhook/tlync-webhook/{hash}', [TlyncPaymentController::class, 'webhook'])->name('tlync.webhook');

// Mollie Payment Routes
Route::post('/webhook/mollie-webhook/{hash}', [MolliePaymentController::class, 'handleGatewayWebhook'])->name('mollie.webhook');
Route::match(['get', 'post'], '/mollie/success', [MolliePaymentController::class, 'paymentMainSuccess'])->name('mollie.success');
Route::match(['get', 'post'], '/mollie/failed', [MolliePaymentController::class, 'paymentFailed'])->name('mollie.failed');

Route::get('/receipt/{id}/preview', [ViewPngController::class, 'preview']); // shows the view to capture
Route::get('/kot/{id}/preview/{kotPlaceid?}', [ViewPngController::class, 'previewKot'])->name('kot.preview'); // shows KOT view to capture

Route::post('/kot/png', [ViewPngController::class, 'storeKot'])->name('kot.png.store'); // saves KOT PNG
Route::post('/order/png', [ViewPngController::class, 'storeOrder'])->name('order.png.store'); // saves Order PNG
Route::post('/report/png', [ViewPngController::class, 'storeReport'])->name('report.png.store'); // saves Report PNG


Route::middleware(['auth', config('jetstream.auth_session'), 'verified'])->group(function () {

    Route::get('/posvue', [PosController::class, 'posvue'])->name('pos.vue');
    Route::get('posvue/order/{id}', [PosController::class, 'ordervue'])->name('pos.order.vue');
    Route::get('posvue/kot/{id}', [PosController::class, 'kotvue'])->name('pos.kot.vue');

    Route::prefix('api/pos')->group(function () {
        Route::get('/menus', [PosApiController::class, 'getMenus']);
        Route::get('/orders/{id}', [PosApiController::class, 'getOrder']);
        Route::get('/get-order-number', [PosApiController::class, 'getOrderNumber']);
        Route::get('/waiters', [PosApiController::class, 'getWaiters']);
        Route::get('/categories', [PosApiController::class, 'getCategories'])->name('api.pos.categories');
        Route::get('/items', [PosApiController::class, 'getMenuItems'])->name('api.pos.items');
        Route::get('/items/category/{categoryId}', [PosApiController::class, 'getMenuItemsByCategory']);
        Route::get('/items/menu/{menuId}', [PosApiController::class, 'getMenuItemsByMenu']);
        Route::get('/extra-charges/{orderType}', [PosApiController::class, 'getExtraCharges'])->name('api.pos.extra-charges');
        Route::get('/tables', [PosApiController::class, 'getTables']);
        Route::get('/reservations/today', [PosApiController::class, 'getTodayReservations']);
        Route::post('/tables/{tableId}/unlock', [PosApiController::class, 'forceUnlockTable']);
        Route::get('/order-types', [PosApiController::class, 'getOrderTypes']);
        Route::get('/delivery-platforms', [PosApiController::class, 'getDeliveryPlatforms']);
        Route::post('/orders', [PosApiController::class, 'submitOrder']);
        Route::get('/customers', [PosApiController::class, 'getCustomers']);
        Route::get('/customers/{id}', [PosApiController::class, 'getCustomer']);
        Route::get('/phone-codes', [PosApiController::class, 'getPhoneCodes']);
        Route::post('/customers', [PosApiController::class, 'saveCustomer']);
        Route::post('/orders/{orderId}/set-customer', [PosApiController::class, 'assignCustomerToOrder']);
        Route::get('/taxes', [PosApiController::class, 'getTaxes']);
        Route::get('/restaurants', [PosApiController::class, 'getRestaurants']);

        // Additional POS AJAX routes
        Route::post('/add-cart-item', [PosApiController::class, 'addCartItem'])->name('api.pos.add-cart-item');
        Route::post('/update-cart-item', [PosApiController::class, 'updateCartItem'])->name('api.pos.update-cart-item');
        Route::post('/delete-cart-item', [PosApiController::class, 'deleteCartItem'])->name('api.pos.delete-cart-item');
        Route::post('/set-table', [PosApiController::class, 'setTable'])->name('api.pos.set-table');
        Route::post('/set-customer', [PosApiController::class, 'setCustomer'])->name('api.pos.set-customer');
        Route::post('/save-order', [PosApiController::class, 'saveOrder'])->name('api.pos.save-order');
        Route::post('/order-type/default', [PosApiController::class, 'saveDefaultOrderTypePreference'])->name('api.pos.order-type-default');
        Route::get('/menu-item/{id}', [PosApiController::class, 'getMenuItem'])->name('api.pos.menu-item');
        Route::get('/menu-item/{id}/variations', [PosApiController::class, 'getMenuItemVariations'])->name('api.pos.menu-item-variations');
        Route::get('/menu-item/{id}/modifiers', [PosApiController::class, 'getMenuItemModifiers'])->name('api.pos.menu-item-modifiers');
        Route::post('/calculate-total', [PosApiController::class, 'calculateTotal'])->name('api.pos.calculate-total');
        Route::post('/update-customer-display', [PosApiController::class, 'updateCustomerDisplay'])->name('api.pos.update-customer-display');
        Route::get('/tables-with-unpaid-orders', [PosApiController::class, 'getTablesWithUnpaidOrders'])->name('api.pos.tables-with-unpaid-orders');
        Route::post('/merge-tables', [PosApiController::class, 'mergeTables'])->name('api.pos.merge-tables');
        Route::post('/clear-merge-session', [PosApiController::class, 'clearMergeSession'])->name('api.pos.clear-merge-session');
        Route::post('/update-order-status/{id}', [PosApiController::class, 'updateOrderStatus'])->name('api.pos.update-order-status');
        Route::post('/cancel-order/{id}', [PosApiController::class, 'cancelOrder'])->name('api.pos.cancel-order');
        Route::delete('/delete-order/{id}', [PosApiController::class, 'deleteOrder'])->name('api.pos.delete-order');
        Route::delete('/orders/{orderId}/items/{itemId}', [PosApiController::class, 'deleteOrderItem'])->name('api.pos.delete-order-item');
        Route::post('/orders/{id}/cancel', [PosApiController::class, 'cancelOrder'])->name('api.pos.orders.cancel');
        Route::post('/orders/{orderId}/remove-charge/{chargeId}', [PosApiController::class, 'removeExtraCharge'])->name('api.pos.remove-extra-charge');
        Route::post('/orders/{orderId}/update-waiter', [PosApiController::class, 'updateWaiter'])->name('api.pos.update-waiter');
    });

    Route::prefix('ajax/pos')->group(function () {
        Route::get('/menus', [PosAjaxController::class, 'getMenus']);
        Route::get('/orders/{id}', [PosAjaxController::class, 'getOrder']);
        Route::get('/get-order-number', [PosAjaxController::class, 'getOrderNumber']);
        Route::get('/waiters', [PosAjaxController::class, 'getWaiters']);
        Route::get('/categories', [PosAjaxController::class, 'getCategories'])->name('ajax.pos.categories');
        Route::get('/items', [PosAjaxController::class, 'getMenuItems'])->name('ajax.pos.items');
        Route::get('/items/category/{categoryId}', [PosAjaxController::class, 'getMenuItemsByCategory']);
        Route::get('/items/menu/{menuId}', [PosAjaxController::class, 'getMenuItemsByMenu']);
        Route::get('/extra-charges/{orderType}', [PosAjaxController::class, 'getExtraCharges'])->name('ajax.pos.extra-charges');
        Route::get('/tables', [PosAjaxController::class, 'getTables']);
        Route::get('/reservations/today', [PosAjaxController::class, 'getTodayReservations']);
        Route::post('/tables/{tableId}/unlock', [PosAjaxController::class, 'forceUnlockTable']);
        Route::get('/order-types', [PosAjaxController::class, 'getOrderTypes']);
        Route::get('/delivery-platforms', [PosAjaxController::class, 'getDeliveryPlatforms']);
        Route::post('/orders', [PosAjaxController::class, 'submitOrder']);
        Route::get('/customers', [PosAjaxController::class, 'getCustomers']);
        Route::get('/customers/{id}', [PosAjaxController::class, 'getCustomer']);
        Route::get('/phone-codes', [PosAjaxController::class, 'getPhoneCodes']);
        Route::post('/customers', [PosAjaxController::class, 'saveCustomer']);
        Route::post('/orders/{orderId}/set-customer', [PosAjaxController::class, 'assignCustomerToOrder']);
        Route::get('/taxes', [PosAjaxController::class, 'getTaxes']);
        Route::get('/restaurants', [PosAjaxController::class, 'getRestaurants']);

        // Additional POS AJAX routes
        Route::post('/add-cart-item', [PosAjaxController::class, 'addCartItem'])->name('ajax.pos.add-cart-item');
        Route::get('/check-menu-item-stock', [PosAjaxController::class, 'checkMenuItemStock'])->name('ajax.pos.check-menu-item-stock');
        Route::post('/update-cart-item', [PosAjaxController::class, 'updateCartItem'])->name('ajax.pos.update-cart-item');
        Route::post('/delete-cart-item', [PosAjaxController::class, 'deleteCartItem'])->name('ajax.pos.delete-cart-item');
        Route::post('/set-table', [PosAjaxController::class, 'setTable'])->name('ajax.pos.set-table');
        Route::post('/set-customer', [PosAjaxController::class, 'setCustomer'])->name('ajax.pos.set-customer');
        Route::post('/save-order', [PosAjaxController::class, 'saveOrder'])->name('ajax.pos.save-order');
        Route::post('/sync-offline-payment', [PosAjaxController::class, 'syncOfflinePayment'])->name('ajax.pos.sync-offline-payment');
        Route::post('/order-type/default', [PosAjaxController::class, 'saveDefaultOrderTypePreference'])->name('ajax.pos.order-type-default');
        Route::get('/menu-item/{id}', [PosAjaxController::class, 'getMenuItem'])->name('ajax.pos.menu-item');
        Route::get('/menu-item/{id}/variations', [PosAjaxController::class, 'getMenuItemVariations'])->name('ajax.pos.menu-item-variations');
        Route::get('/menu-item/{id}/modifiers', [PosAjaxController::class, 'getMenuItemModifiers'])->name('ajax.pos.menu-item-modifiers');
        Route::post('/calculate-total', [PosAjaxController::class, 'calculateTotal'])->name('ajax.pos.calculate-total');
        Route::post('/update-customer-display', [PosAjaxController::class, 'updateCustomerDisplay'])->name('ajax.pos.update-customer-display');
        Route::get('/tables-with-unpaid-orders', [PosAjaxController::class, 'getTablesWithUnpaidOrders'])->name('ajax.pos.tables-with-unpaid-orders');
        Route::post('/merge-tables', [PosAjaxController::class, 'mergeTables'])->name('ajax.pos.merge-tables');
        Route::post('/clear-merge-session', [PosAjaxController::class, 'clearMergeSession'])->name('ajax.pos.clear-merge-session');
        Route::post('/clear-cache', [PosAjaxController::class, 'clearPosCache'])->name('ajax.pos.clear-cache');
        Route::post('/update-order-status/{id}', [PosAjaxController::class, 'updateOrderStatus'])->name('ajax.pos.update-order-status');
        Route::post('/cancel-order/{id}', [PosAjaxController::class, 'cancelOrder'])->name('ajax.pos.cancel-order');
        Route::delete('/delete-order/{id}', [PosAjaxController::class, 'deleteOrder'])->name('ajax.pos.delete-order');
        Route::delete('/orders/{orderId}/items/{itemId}', [PosAjaxController::class, 'deleteOrderItem'])->name('ajax.pos.delete-order-item');
        Route::post('/orders/{id}/cancel', [PosAjaxController::class, 'cancelOrder'])->name('ajax.pos.orders.cancel');
        Route::post('/orders/{orderId}/remove-charge/{chargeId}', [PosAjaxController::class, 'removeExtraCharge'])->name('ajax.pos.remove-extra-charge');
        Route::post('/orders/{orderId}/update-discount', [PosAjaxController::class, 'updateOrderDiscount'])->name('ajax.pos.update-order-discount');
        Route::post('/orders/{orderId}/update-note', [PosAjaxController::class, 'updateOrderNote'])->name('ajax.pos.update-order-note');
        Route::post('/orders/{orderId}/update-waiter', [PosAjaxController::class, 'updateWaiter'])->name('ajax.pos.update-waiter');
        Route::post('/orders/{orderId}/update-delivery-executive', [PosAjaxController::class, 'updateDeliveryExecutive'])->name('ajax.pos.update-delivery-executive');
        Route::post('/orders/{orderId}/print', [PosAjaxController::class, 'ajaxPrintOrder'])->name('ajax.pos.print-order');
        Route::post('/orders/{orderId}/print-kot', [PosAjaxController::class, 'ajaxPrintKotForOrder'])->name('ajax.pos.print-kot-order');
        Route::post('/kot/{kotId}/print', [PosAjaxController::class, 'ajaxPrintKot'])->name('ajax.pos.print-kot');

        // Loyalty (optional module) - JS/AJAX POS integration
        Route::get('/loyalty/summary', [PosAjaxController::class, 'getLoyaltySummary'])->name('ajax.pos.loyalty.summary');
        Route::post('/loyalty/redeem', [PosAjaxController::class, 'redeemLoyaltyPoints'])->name('ajax.pos.loyalty.redeem');
        Route::post('/loyalty/reset', [PosAjaxController::class, 'resetLoyaltyRedemption'])->name('ajax.pos.loyalty.reset');
        Route::post('/loyalty/stamp-auto-preview', [PosAjaxController::class, 'getAutoStampPreview'])->name('ajax.pos.loyalty.stamp-auto-preview');

        // Hotel room-service integrations for AJAX POS
        Route::get('/hotel/stays', [PosAjaxController::class, 'getHotelStays'])->name('ajax.pos.hotel.stays');
        Route::get('/hotel/room-picker', [PosAjaxController::class, 'getHotelRoomPickerList'])->name('ajax.pos.hotel.room-picker');
    });
});
