<?php

use App\Http\Controllers\Api\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\Admin\ProductInventoryController;
use App\Http\Controllers\Api\Admin\ComboController as AdminComboController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\GalleryImageController as AdminGalleryController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentReportController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\PricingPlanController as AdminPricingPlanController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\ServiceCategoryController as AdminServiceCategoryController;
use App\Http\Controllers\Api\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Api\Admin\SiteSettingController as AdminSiteSettingController;
use App\Http\Controllers\Api\Admin\StylistController as AdminStylistController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\VideoController as AdminVideoController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Public\AccountController;
use App\Http\Controllers\Api\Public\AppointmentController;
use App\Http\Controllers\Api\Public\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Public\ComboController;
use App\Http\Controllers\Api\Public\GalleryController;
use App\Http\Controllers\Api\Public\GoogleAuthController;
use App\Http\Controllers\Api\Public\OrderController;
use App\Http\Controllers\Api\Public\PaymentController;
use App\Http\Controllers\Api\Public\PricingPlanController;
use App\Http\Controllers\Api\Public\ProductCheckoutController;
use App\Http\Controllers\Api\Public\ProductController;
use App\Http\Controllers\Api\Public\ReviewController as CustomerReviewController;
use App\Http\Controllers\Api\Public\SearchController;
use App\Http\Controllers\Api\Public\ServiceCategoryController;
use App\Http\Controllers\Api\Public\ServiceController;
use App\Http\Controllers\Api\Public\SiteSettingController;
use App\Http\Controllers\Api\Public\StylistController;
use App\Http\Controllers\Api\Public\VideoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API — consumed by the React storefront. Read-only, active records
| only, plus the public appointment request endpoint.
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::match(['put', 'patch'], 'password', [AuthController::class, 'updatePassword']);
    });
});

Route::get('service-categories', [ServiceCategoryController::class, 'index']);
Route::get('service-categories/{serviceCategory}', [ServiceCategoryController::class, 'show']);
Route::get('services', [ServiceController::class, 'index']);
Route::get('services/{service}', [ServiceController::class, 'show']);
Route::get('products', [ProductController::class, 'index']);
Route::get('combos', [ComboController::class, 'index']);
Route::get('gallery', [GalleryController::class, 'index']);
Route::get('videos', [VideoController::class, 'index']);
Route::get('stylists', [StylistController::class, 'index']);
Route::get('pricing-plans', [PricingPlanController::class, 'index']);
Route::get('site-settings', [SiteSettingController::class, 'index']);
Route::get('reviews', [CustomerReviewController::class, 'index']);
// A customer's own review — pending admin approval, like a staff-entered
// Google review. Requires a signed-in account, same as `POST /appointments`.
Route::post('reviews', [CustomerReviewController::class, 'store'])->middleware(['throttle:6,1', 'auth:sanctum']);
Route::get('search', [SearchController::class, 'index']);

// Busy-slot lookup stays public — visitors must be able to see availability
// (and search/browse the catalogue) without an account. Creating the
// appointment itself now requires a signed-in customer (see
// Api\Public\AppointmentController::store) — the ownership requirement is
// enforced here via middleware, not just trusted from the frontend.
Route::get('appointments/busy', [AppointmentController::class, 'busy']);
Route::post('appointments', [AppointmentController::class, 'store'])->middleware(['throttle:10,1', 'auth:sanctum']);

// Product checkout (Buy Now / cart) — signed-in customers only. `checkout`
// prices the basket server-side (App\Support\OrderPricing) and opens a
// Razorpay order; the product order itself is only created by `verify`, after
// the payment signature checks out.
Route::middleware(['throttle:10,1', 'auth:sanctum'])->group(function () {
    Route::post('checkout', [ProductCheckoutController::class, 'store']);
    Route::post('checkout/{checkout}/verify', [ProductCheckoutController::class, 'verify']);
});

/*
|--------------------------------------------------------------------------
| Customer accounts — separate from the staff /auth/* block above (which is
| untouched), but the same underlying User model + Sanctum tokens.
|--------------------------------------------------------------------------
*/
Route::prefix('account')->group(function () {
    Route::post('register', [CustomerAuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
    Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:6,1');
    Route::get('google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('google/callback', [GoogleAuthController::class, 'callback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [CustomerAuthController::class, 'logout']);
        Route::get('me', [CustomerAuthController::class, 'me']);
        Route::match(['put', 'patch'], 'profile', [AccountController::class, 'update']);
        Route::match(['put', 'patch'], 'password', [AccountController::class, 'updatePassword']);
        Route::get('appointments', [AccountController::class, 'appointments']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
    });
});

// Razorpay TEST Mode "Confirmation Fee" step — order creation + signature
// verification for an already-created, still-pending online appointment.
Route::post('appointments/{appointment}/payment/order', [PaymentController::class, 'order'])->middleware('throttle:10,1');
Route::post('appointments/{appointment}/payment/verify', [PaymentController::class, 'verify'])->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| Admin API — Sanctum token + granular permission checks.
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {

    Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');

    // Users
    Route::middleware('permission:users.view')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/{user}', [UserController::class, 'show']);
    });
    Route::middleware('permission:users.manage')->group(function () {
        Route::post('users', [UserController::class, 'store']);
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
    });
    // Deliberately NOT under `permission:users.manage` above: `admin` doesn't
    // hold that permission, but must still be able to reset another admin's
    // or a customer's password (never a superadmin's) per the password
    // policy. Any authenticated staff member may call this; the actual
    // authorization — including the superadmin-target block — lives in
    // UpdateUserPasswordRequest.
    Route::match(['put', 'patch'], 'users/{user}/password', [UserController::class, 'updatePassword']);

    // Roles & permissions
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view');
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/{role}', [RoleController::class, 'show']);
    });
    Route::middleware('permission:roles.manage')->group(function () {
        Route::post('roles', [RoleController::class, 'store']);
        Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });

    // Service categories
    Route::middleware('permission:services.view')->group(function () {
        Route::get('service-categories', [AdminServiceCategoryController::class, 'index']);
        Route::get('service-categories/{serviceCategory}', [AdminServiceCategoryController::class, 'show']);
        Route::get('services', [AdminServiceController::class, 'index']);
        Route::get('services/{service}', [AdminServiceController::class, 'show']);
    });
    Route::post('service-categories/reorder', [AdminServiceCategoryController::class, 'reorder'])->middleware('permission:services.update');
    Route::post('service-categories', [AdminServiceCategoryController::class, 'store'])->middleware('permission:services.create');
    Route::match(['put', 'patch'], 'service-categories/{serviceCategory}', [AdminServiceCategoryController::class, 'update'])->middleware('permission:services.update');
    Route::delete('service-categories/{serviceCategory}', [AdminServiceCategoryController::class, 'destroy'])->middleware('permission:services.delete');

    Route::post('services/reorder', [AdminServiceController::class, 'reorder'])->middleware('permission:services.update');
    Route::post('services', [AdminServiceController::class, 'store'])->middleware('permission:services.create');
    Route::match(['put', 'patch'], 'services/{service}', [AdminServiceController::class, 'update'])->middleware('permission:services.update');
    Route::delete('services/{service}', [AdminServiceController::class, 'destroy'])->middleware('permission:services.delete');

    // Pricing plans
    Route::get('pricing-plans', [AdminPricingPlanController::class, 'index'])->middleware('permission:pricing.view');
    Route::get('pricing-plans/{pricingPlan}', [AdminPricingPlanController::class, 'show'])->middleware('permission:pricing.view');
    Route::post('pricing-plans/reorder', [AdminPricingPlanController::class, 'reorder'])->middleware('permission:pricing.manage');
    Route::post('pricing-plans', [AdminPricingPlanController::class, 'store'])->middleware('permission:pricing.manage');
    Route::match(['put', 'patch'], 'pricing-plans/{pricingPlan}', [AdminPricingPlanController::class, 'update'])->middleware('permission:pricing.manage');
    Route::delete('pricing-plans/{pricingPlan}', [AdminPricingPlanController::class, 'destroy'])->middleware('permission:pricing.manage');

  // Products (retail shelf shown on the public site)
Route::middleware('permission:products.view')->group(function () {
    Route::get('products', [AdminProductController::class, 'index']);
    Route::get('products/{product}', [AdminProductController::class, 'show']);
});

Route::post('products/reorder', [AdminProductController::class, 'reorder'])
    ->middleware('permission:products.update');

Route::post('products', [AdminProductController::class, 'store'])
    ->middleware('permission:products.create');

Route::match(['put', 'patch'], 'products/{product}', [AdminProductController::class, 'update'])
    ->middleware('permission:products.update');

Route::delete('products/{product}', [AdminProductController::class, 'destroy'])
    ->middleware('permission:products.delete');

// Product stock management
Route::get('inventory', [ProductInventoryController::class, 'index'])
    ->middleware('permission:products.view');

Route::get('inventory/history', [ProductInventoryController::class, 'history'])
    ->middleware('permission:products.view');

Route::post('inventory/products/{product}/restock', [ProductInventoryController::class, 'restock'])
    ->middleware('permission:products.update');

Route::post('inventory/products/{product}/adjust', [ProductInventoryController::class, 'adjust'])
    ->middleware('permission:products.update');
    // Combo products (included products + combo-specific prices) — same
    // permissions as the product catalogue they're built from.
    Route::middleware('permission:products.view')->group(function () {
        Route::get('combos', [AdminComboController::class, 'index']);
        Route::get('combos/{combo}', [AdminComboController::class, 'show']);
    });
    Route::post('combos', [AdminComboController::class, 'store'])->middleware('permission:products.create');
    Route::match(['put', 'patch'], 'combos/{combo}', [AdminComboController::class, 'update'])->middleware('permission:products.update');
    Route::delete('combos/{combo}', [AdminComboController::class, 'destroy'])->middleware('permission:products.delete');

    // Product orders
    Route::get('orders', [AdminOrderController::class, 'index'])->middleware('permission:orders.view');
    Route::get('orders/{order}', [AdminOrderController::class, 'show'])->middleware('permission:orders.view');
    Route::match(['put', 'patch'], 'orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->middleware('permission:orders.manage');

    // Appointments (operational list + history + offline history all use the
    // same filtered endpoint; offline history just pins ?source=offline)
    Route::get('appointments', [AdminAppointmentController::class, 'index'])->middleware('permission:appointments.view');
    Route::get('appointments/export', [AdminAppointmentController::class, 'export'])->middleware('permission:appointments.view');
    Route::post('appointments', [AdminAppointmentController::class, 'store'])->middleware('permission:appointments.offline');
    Route::get('appointments/{appointment}', [AdminAppointmentController::class, 'show'])->middleware('permission:appointments.view');
    // Confirm a pending appointment: verifies the slot is free for the stylist
    // and locks the full service duration atomically.
    Route::post('appointments/{appointment}/confirm', [AdminAppointmentController::class, 'confirm'])->middleware('permission:appointments.manage');
    Route::match(['put', 'patch'], 'appointments/{appointment}', [AdminAppointmentController::class, 'update'])->middleware('permission:appointments.manage');
    Route::delete('appointments/{appointment}', [AdminAppointmentController::class, 'destroy'])->middleware('permission:appointments.manage');

    // Stylists
    Route::middleware('permission:stylists.view')->group(function () {
        Route::get('stylists', [AdminStylistController::class, 'index']);
        Route::get('stylists/{stylist}', [AdminStylistController::class, 'show']);
    });
    Route::post('stylists/reorder', [AdminStylistController::class, 'reorder'])->middleware('permission:stylists.manage');
    Route::post('stylists', [AdminStylistController::class, 'store'])->middleware('permission:stylists.manage');
    Route::match(['put', 'patch'], 'stylists/{stylist}', [AdminStylistController::class, 'update'])->middleware('permission:stylists.manage');
    Route::delete('stylists/{stylist}', [AdminStylistController::class, 'destroy'])->middleware('permission:stylists.manage');

    // Payments / completed-appointment reporting
    Route::get('payments', [PaymentReportController::class, 'index'])->middleware('permission:payments.view');
    Route::get('payments/export', [PaymentReportController::class, 'export'])->middleware('permission:payments.export');

    // Gallery
    Route::get('gallery', [AdminGalleryController::class, 'index'])->middleware('permission:gallery.view');
    Route::get('gallery/{gallery}', [AdminGalleryController::class, 'show'])->middleware('permission:gallery.view');
    Route::post('gallery/reorder', [AdminGalleryController::class, 'reorder'])->middleware('permission:gallery.manage');
    Route::post('gallery', [AdminGalleryController::class, 'store'])->middleware('permission:gallery.manage');
    Route::match(['put', 'patch'], 'gallery/{gallery}', [AdminGalleryController::class, 'update'])->middleware('permission:gallery.manage');
    Route::delete('gallery/{gallery}', [AdminGalleryController::class, 'destroy'])->middleware('permission:gallery.manage');

    // Videos (homepage Video marquee)
    Route::get('videos', [AdminVideoController::class, 'index'])->middleware('permission:videos.view');
    Route::get('videos/{video}', [AdminVideoController::class, 'show'])->middleware('permission:videos.view');
    Route::post('videos/reorder', [AdminVideoController::class, 'reorder'])->middleware('permission:videos.manage');
    Route::post('videos', [AdminVideoController::class, 'store'])->middleware('permission:videos.manage');
    Route::match(['put', 'patch'], 'videos/{video}', [AdminVideoController::class, 'update'])->middleware('permission:videos.manage');
    Route::delete('videos/{video}', [AdminVideoController::class, 'destroy'])->middleware('permission:videos.manage');

    // Reviews (manually admin-entered Google reviews — no scraping/sync)
    Route::get('reviews', [AdminReviewController::class, 'index'])->middleware('permission:reviews.view');
    Route::get('reviews/{review}', [AdminReviewController::class, 'show'])->middleware('permission:reviews.view');
    Route::post('reviews/reorder', [AdminReviewController::class, 'reorder'])->middleware('permission:reviews.manage');
    Route::post('reviews', [AdminReviewController::class, 'store'])->middleware('permission:reviews.manage');
    Route::match(['put', 'patch'], 'reviews/{review}', [AdminReviewController::class, 'update'])->middleware('permission:reviews.manage');
    Route::delete('reviews/{review}', [AdminReviewController::class, 'destroy'])->middleware('permission:reviews.manage');

    // Site settings
    Route::get('site-settings', [AdminSiteSettingController::class, 'index'])->middleware('permission:settings.view');
    Route::match(['put', 'patch'], 'site-settings', [AdminSiteSettingController::class, 'update'])->middleware('permission:settings.manage');
});
