<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreStylistRequest;
use App\Http\Requests\Admin\SyncStylistServicesRequest;
use App\Http\Requests\Admin\UpdateStylistDateHoursRequest;
use App\Http\Requests\Admin\UpdateStylistRequest;
use App\Http\Requests\Admin\UpdateStylistWorkHoursRequest;
use App\Http\Resources\StylistResource;
use App\Models\Appointment;
use App\Models\ServiceCategory;
use App\Models\Stylist;
use App\Models\StylistDateHour;
use App\Models\StylistWorkHour;
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
            ->withCount([
                'appointments',
                'services',
                'workHours',
                // Upcoming dates with custom hours (a professional can be scheduled by calendar alone).
                'dateHours as custom_days_count' => fn ($q) => $q
                    ->whereNotNull('start_time')
                    ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString()),
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

        $stylist = DB::transaction(function () use ($data) {
            $stylist = Stylist::create($data);

            // A new professional starts on the studio's default weekly hours
            // (they still need services before they can be booked).
            self::replaceWorkHours($stylist, collect(range(0, 6))->map(fn ($day) => [
                'day_of_week' => $day,
                'ranges' => array_map(
                    fn ($r) => ['start' => $r[0], 'end' => $r[1]],
                    BookingAvailability::defaultRanges(),
                ),
            ])->all());

            return $stylist;
        });

        return (new StylistResource($stylist->loadCount(['appointments', 'services', 'workHours'])))
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
     * they offer, their weekly hours, the studio's opening hours (which cap
     * those hours) and the full service catalogue to pick from.
     */
    public function setup(Stylist $stylist)
    {
        return response()->json(['data' => $this->setupPayload($stylist)]);
    }

    /** Replace the set of services this professional offers. */
    public function syncServices(SyncStylistServicesRequest $request, Stylist $stylist)
    {
        $stylist->services()->sync($request->input('service_ids', []));

        return response()->json(['data' => $this->setupPayload($stylist)]);
    }

    /** Replace this professional's weekly working hours. */
    /**
     * Set hours for specific calendar dates: a day off, custom ranges, or back
     * to the weekly pattern. Responds with the refreshed setup plus a list of
     * dates where existing (pending/confirmed) appointments now fall outside
     * the professional's hours — they are NOT cancelled, the admin is told.
     */
    public function updateDateHours(UpdateStylistDateHoursRequest $request, Stylist $stylist)
    {
        $days = $request->input('days', []);

        DB::transaction(function () use ($stylist, $days) {
            foreach ($days as $day) {
                $stylist->dateHours()->whereDate('date', $day['date'])->delete();

                if ($day['mode'] === 'off') {
                    $stylist->dateHours()->create(['date' => $day['date']]);
                } elseif ($day['mode'] === 'custom') {
                    foreach ($day['ranges'] as $range) {
                        $stylist->dateHours()->create([
                            'date' => $day['date'],
                            'start_time' => $range['start'],
                            'end_time' => $range['end'],
                        ]);
                    }
                }
            }
        });

        return response()->json([
            'data' => $this->setupPayload($stylist),
            'warnings' => $this->appointmentsOutsideHours($stylist, collect($days)->where('mode', '!=', 'regular')->pluck('date')->all()),
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

    public function updateWorkHours(UpdateStylistWorkHoursRequest $request, Stylist $stylist)
    {
        DB::transaction(fn () => self::replaceWorkHours($stylist, $request->input('days', [])));

        return response()->json(['data' => $this->setupPayload($stylist)]);
    }

    /** Delete and re-insert a professional's weekly ranges from [{day_of_week, ranges: [{start, end}]}]. */
    private static function replaceWorkHours(Stylist $stylist, array $days): void
    {
        $stylist->workHours()->delete();

        $rows = [];
        foreach ($days as $day) {
            foreach ($day['ranges'] ?? [] as $range) {
                $rows[] = [
                    'day_of_week' => (int) $day['day_of_week'],
                    'start_time' => $range['start'],
                    'end_time' => $range['end'],
                ];
            }
        }

        if ($rows) {
            $stylist->workHours()->createMany($rows);
        }
    }

    private function setupPayload(Stylist $stylist): array
    {
        $stylist->unsetRelation('workHours')->load('workHours');
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
            'work_hours' => StylistWorkHour::weekly($stylist->workHours),
            // Calendar dates that override the weekly pattern (today onwards).
            'date_hours' => StylistDateHour::byDate(
                $stylist->dateHours()
                    ->whereDate('date', '>=', Carbon::now(BookingAvailability::TZ)->toDateString())
                    ->whereDate('date', '<=', Carbon::now(BookingAvailability::TZ)->addDays(UpdateStylistDateHoursRequest::MAX_DAYS_AHEAD)->toDateString())
                    ->get()
            ),
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
