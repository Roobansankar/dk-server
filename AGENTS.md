# DK StyleHub Backend — notes for agents

Laravel 13 REST API for the DK StyleHub salon site. See `README.md` for setup.

## Conventions

- **Auth:** Sanctum bearer tokens. Admin routes gated by `permission:<name>`
  middleware (spatie/laravel-permission). `superadmin` role bypasses every check
  via `Gate::before` in `AppServiceProvider`.
- **Permissions catalogue:** `App\Models\Permission::GROUPS` is the single source
  of truth. Add new permissions there, then re-run `RolePermissionSeeder`.
- **API shape:** every endpoint returns an API Resource (`app/Http/Resources`).
  Never return raw models. Public endpoints expose active records only.
- **Requests:** all writes go through Form Requests in `app/Http/Requests`.
- **Slugs:** generated via `App\Support\Slug::unique()`.
- **Images:** `App\Support\ImageUploader` only. Disk = `config('salon.media_disk')`.
- **Gender:** lives on `service_categories`; services inherit it. Appointments
  store their own `gender` + denormalised `category_name` / `service_name`.
- **Service config:** services carry `duration_minutes`, `price`,
  `advance_percentage` (0–100). `Service::advance_amount` is derived. On booking,
  `Appointment::applyServiceSnapshot()` copies duration/price/advance onto the
  appointment; `payment_status` is `unpaid|advance_paid|paid` and drives the
  `amount_received` / `remaining_amount` accessors.
- **Appointment list filters:** shared via `App\Support\AppointmentFilters` —
  used by the admin appointment list, history view and the payment report.
- **Payments report:** `payments.view` / `payments.export` permissions.
  `PaymentReportController` (completed appointments + totals + an .xlsx
  export written with OpenSpout).
- **Seeders:** `DatabaseSeeder` runs production-safe seeders always; `DevSeeder`
  (fake salon data) only when `! app()->isProduction()`.

## Before finishing

Run `php artisan test` and `./vendor/bin/pint`.

Do not install `laravel/boost` or other tooling unless asked.
