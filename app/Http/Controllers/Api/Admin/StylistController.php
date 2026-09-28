<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Public\StylistController as PublicStylistController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreStylistRequest;
use App\Http\Requests\Admin\SyncStylistServicesRequest;
use App\Http\Requests\Admin\UpdateStylistDateHoursRequest;
use App\Http\Requests\Admin\UpdateStylistRequest;
use App\Http\Requests\Admin\UpdateStylistWeeklyHoursRequest;
use App\Http\Resources\StylistResource;
use App\Models\Appointment;
use App\Models\ServiceCategory;
use App\Models\StudioHoliday;
use App\Models\Stylist;
use App\Models\StylistDateHour;
use App\Models\StylistWeeklyHour;
use App\Support\BookingAvailability;
use App\Support\ImageUploader;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StylistController extends Controller
{
    public function index(Request $request)
    {
        $stylists = Stylist::query()
            ->with([
                // Which services each offers, with their own prices (the offline-booking form uses them).
                'services' => fn ($q) => $q->select('services.id'),
                // Specific dates, explicit days off and the standing weekly schedule —
                // the offline-booking form combines all three, exactly like the public
                // booking page, to decide which dates are actually offered.
                'dateHours' => fn ($q) => $q
                    ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
                    ->whereDate('date', '<=', Carbon::now(BookingAvailability::TZ)->addDays(PublicStylistController::DATE_HOURS_DAYS_AHEAD)->toDateString()),
                'dateClosures' => fn ($q) => $q
                    ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
                    ->whereDate('date', '<=', Carbon::now(BookingAvailability::TZ)->addDays(PublicStylistController::DATE_HOURS_DAYS_AHEAD)->toDateString()),
                'weeklyHours',
            ])
            ->withCount([
                'appointments',
                'services',
                // Upcoming calendar dates with their OWN explicit hours — not the full
                // bookable count once a weekly schedule is involved, but still a useful signal.
                'dateHours as upcoming_days_count' => fn ($q) => $q
                    ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
                    ->select(DB::raw('count(distinct date)')),
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->paginate($request->integer('per_page', 50));

        return StylistResource::collection($stylists);
    }

    public function store(StoreStylistRequest $request)
    {
        $data = $request->safe()->except(['image']);
        $data['slug'] = Slug::unique(Stylist::class, $request->string('name'));

        if ($request->hasFile('image')) {
            $data['image_path'] = ImageUploader::store($request->file('image'), 'stylists');
        }

        // A new professional has no services and no dates: nothing is bookable
        // until an admin sets them up on their "Services & hours" page.
        $stylist = Stylist::create($data);

        return (new StylistResource($stylist->loadCount(['appointments', 'services'])))
            ->response()->setStatusCode(201);
    }

    public function show(Stylist $stylist)
    {
        return new StylistResource($stylist->loadCount('appointments'));
    }

    public function update(UpdateStylistRequest $request, Stylist $stylist)
    {
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->filled('name')) {
            $data['slug'] = Slug::unique(Stylist::class, $data['name'], 'slug', null, $stylist->id);
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($stylist->image_path);
            $data['image_path'] = ImageUploader::store($request->file('image'), 'stylists');
        } elseif ($request->boolean('remove_image')) {
            ImageUploader::delete($stylist->image_path);
            $data['image_path'] = null;
        }

        $stylist->update($data);

        return new StylistResource($stylist->loadCount('appointments'));
    }

    public function destroy(Stylist $stylist)
    {
        if ($stylist->appointments()->exists()) {
            return response()->json([
                'message' => 'This stylist is linked to appointments. Deactivate them instead to keep appointment history intact.',
            ], 422);
        }

        ImageUploader::delete($stylist->image_path);
        $stylist->delete();

        return response()->noContent();
    }

    /**
     * Everything the "Services & hours" page needs for one professional: what
     * they offer, the calendar dates they're available on, the studio's opening
     * hours (which cap those hours) and the full service catalogue to pick from.
     */
    public function setup(Stylist $stylist)
    {
        return response()->json(['data' => $this->setupPayload($stylist)]);
    }

    /**
     * Replace the set of services this professional offers — optionally with
     * their own price / advance percentage per service (blank = the service's
     * standard). Sending only `service_ids` leaves any terms already set alone.
     */
    public function syncServices(SyncStylistServicesRequest $request, Stylist $stylist)
    {
        if ($request->has('services')) {
            $stylist->services()->sync(
                collect($request->input('services'))
                    ->mapWithKeys(fn ($service) => [
                        $service['id'] => [
                            'price' => $service['price'] ?? null,
                            'advance_percentage' => $service['advance_percentage'] ?? null,
                        ],
                    ])
                    ->all()
            );
        } else {
            $stylist->services()->sync($request->input('service_ids', []));
        }

        return response()->json(['data' => $this->setupPayload($stylist)]);
    }

    /**
     * Set a professional's hours on specific calendar dates — `custom` gives
     * the date its own ranges, `closed` marks it explicitly not available
     * (overriding an otherwise-open weekly schedule), `clear` removes any
     * override so the date follows the weekly schedule instead. Responds with
     * the refreshed setup plus a list of dates where existing
     * (pending/confirmed) appointments now fall outside the professional's
     * hours — they are NOT cancelled, the admin is told.
     */
    public function updateDateHours(UpdateStylistDateHoursRequest $request, Stylist $stylist)
    {
        $days = $request->input('days', []);

        DB::transaction(function () use ($stylist, $days) {
            foreach ($days as $day) {
                $stylist->dateHours()->whereDate('date', $day['date'])->delete();
                $stylist->dateClosures()->whereDate('date', $day['date'])->delete();

                if ($day['mode'] === 'custom') {
                    foreach ($day['ranges'] as $range) {
                        $stylist->dateHours()->create([
                            'date' => $day['date'],
                            'start_time' => $range['start'],
                            'end_time' => $range['end'],
                        ]);
                    }
                } elseif ($day['mode'] === 'closed') {
                    $stylist->dateClosures()->create(['date' => $day['date']]);
                }
            }
        });

        return response()->json([
            'data' => $this->setupPayload($stylist),
            'warnings' => $this->appointmentsOutsideHours($stylist, collect($days)->pluck('date')->all()),
        ]);
    }

    /**
     * Set a professional's standing weekly schedule ("every Monday 10–6",
     * with no end date) — the default a calendar date falls back to when it
     * has no hours of its own and isn't explicitly closed. Sending an empty
     * range list for a weekday clears its standing hours (no longer part of
     * the pattern). Weekdays not sent are left as they are.
     */
    public function updateWeeklyHours(UpdateStylistWeeklyHoursRequest $request, Stylist $stylist)
    {
        $days = $request->input('days', []);

        DB::transaction(function () use ($stylist, $days) {
            foreach ($days as $day) {
                $stylist->weeklyHours()->where('weekday', $day['weekday'])->delete();

                foreach ($day['ranges'] ?? [] as $range) {
                    $stylist->weeklyHours()->create([
                        'weekday' => $day['weekday'],
                        'start_time' => $range['start'],
                        'end_time' => $range['end'],
                    ]);
                }
            }
        });

        // A weekly change can ripple across every future date on the touched
        // weekdays — re-check every upcoming appointment rather than trying
        // to enumerate which dates match.
        $upcomingDates = $stylist->appointments()
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->whereDate('appointment_date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
            ->whereNotNull('appointment_time')
            ->distinct()
            ->pluck('appointment_date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        return response()->json([
            'data' => $this->setupPayload($stylist),
            'warnings' => $this->appointmentsOutsideHours($stylist, $upcomingDates),
        ]);
    }

    /**
     * Dates (from $dates) on which this professional has pending/confirmed
     * appointments that no longer fit inside their working hours.
     *
     * @param  array<int, string>  $dates
     * @return array<int, array{date: string, count: int}>
     */
    private function appointmentsOutsideHours(Stylist $stylist, array $dates): array
    {
        $warnings = [];

        foreach ($dates as $date) {
            $windows = BookingAvailability::windows($stylist, $date);

            $count = Appointment::query()
                ->where('stylist_id', $stylist->id)
                ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
                ->whereDate('appointment_date', $date)
                ->whereNotNull('appointment_time')
                ->get()
                ->filter(function (Appointment $appointment) use ($windows) {
                    $start = BookingAvailability::minutes($appointment->appointment_time->format('H:i'));
                    $end = $start + (int) $appointment->duration_minutes;

                    foreach ($windows as [$from, $to]) {
                        if ($from <= $start && $end <= $to) {
                            return false;
                        }
                    }

                    return true;
                })
                ->count();

            if ($count > 0) {
                $warnings[] = ['date' => $date, 'count' => $count];
            }
        }

        return $warnings;
    }

    private function setupPayload(Stylist $stylist): array
    {
        $bounds = BookingAvailability::shopBounds();

        return [
            'stylist' => [
                'id' => $stylist->id,
                'name' => $stylist->name,
                'bio' => $stylist->bio,
                'image_url' => ImageUploader::url($stylist->image_path),
                'status' => $stylist->status,
            ],
            'service_ids' => $stylist->services()->pluck('services.id')->values()->all(),
            // Their own price / advance % per service (only where set; the rest use the standard).
            'service_terms' => $stylist->unsetRelation('services')->load('services')->serviceTerms(),
            // The dates they can be booked on (today onwards) and the hours for each — a
            // specific override, always winning over the weekly schedule below. A date
            // that isn't listed here follows the weekly schedule instead (see date_closures
            // for a date explicitly excluded from it).
            'date_hours' => StylistDateHour::byDate(
                $stylist->dateHours()
                    ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
                    ->whereDate('date', '<=', Carbon::now(BookingAvailability::TZ)->addDays(UpdateStylistDateHoursRequest::MAX_DAYS_AHEAD)->toDateString())
                    ->get()
            ),
            // Calendar dates explicitly marked not available despite the weekly schedule —
            // e.g. a holiday. Only matters for a date with no entry above.
            'date_closures' => $stylist->dateClosures()
                ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
                ->whereDate('date', '<=', Carbon::now(BookingAvailability::TZ)->addDays(UpdateStylistDateHoursRequest::MAX_DAYS_AHEAD)->toDateString())
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => $row->date->toDateString())
                ->values(),
            // Their standing weekly schedule ("every Monday 10–6", no end date): { "1": [{start,
            // end}, …] }, keyed "0" (Sunday) … "6" (Saturday). A weekday not listed has none.
            // A specific date above, or a closure, overrides this for that one date.
            'weekly_hours' => StylistWeeklyHour::byWeekday($stylist->weeklyHours),
            // Studio-wide closed days (today onwards) — the whole studio is closed on
            // these dates, whatever this professional's own hours say.
            'studio_holidays' => StudioHoliday::query()
                ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
                ->orderBy('date')
                ->get()
                ->map(fn (StudioHoliday $holiday) => [
                    'date' => $holiday->date->toDateString(),
                    'name' => $holiday->name,
                ])
                ->values(),
            'shop_hours' => $bounds
                ? ['opens' => BookingAvailability::format($bounds[0]), 'closes' => BookingAvailability::format($bounds[1])]
                : null,
            'categories' => ServiceCategory::query()
                ->with(['services' => fn ($q) => $q->ordered()])
                ->orderBy('gender')
                ->ordered()
                ->get()
                ->map(fn (ServiceCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'gender' => $category->gender,
                    'category_type' => $category->category_type,
                    'status' => $category->status,
                    'services' => $category->services->map(fn ($service) => [
                        'id' => $service->id,
                        'name' => $service->name,
                        'duration_minutes' => $service->duration_minutes,
                        'price' => $service->price !== null ? (float) $service->price : null,
                        'advance_percentage' => (int) $service->advance_percentage,
                        'status' => $service->status,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                Stylist::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
