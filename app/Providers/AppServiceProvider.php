<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Combo;
use App\Models\GalleryImage;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Models\Review;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Video;
use App\Policies\AppointmentPolicy;
use App\Policies\GalleryImagePolicy;
use App\Policies\PricingPlanPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\RolePolicy;
use App\Policies\ServiceCategoryPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SiteSettingPolicy;
use App\Policies\UserPolicy;
use App\Policies\VideoPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Short names stored in catalogue_images.imageable_type, instead of class names.
        Relation::morphMap([
            'product' => Product::class,
            'combo' => Combo::class,
        ]);

        // Baseline API rate limit (per authenticated user, else per IP).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // A superadmin implicitly passes every authorization check.
        Gate::before(fn (User $user, string $ability) => $user->hasRole(Role::SUPERADMIN) ? true : null);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(ServiceCategory::class, ServiceCategoryPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(PricingPlan::class, PricingPlanPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(GalleryImage::class, GalleryImagePolicy::class);
        Gate::policy(SiteSetting::class, SiteSettingPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(Video::class, VideoPolicy::class);

        // This is an API-only backend with no `password.reset` web route for
        // the default ResetPassword notification to link to (it would throw
        // RouteNotFoundException without this). Point it at the React SPA's
        // own reset-password page instead.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return sprintf(
                '%s/reset-password?token=%s&email=%s',
                rtrim(config('salon.frontend_url'), '/'),
                $token,
                urlencode($user->email)
            );
        });
    }
}
