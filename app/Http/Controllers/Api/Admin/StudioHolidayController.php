<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudioHolidayRequest;
use App\Http\Requests\Admin\UpdateStudioHolidayRequest;
use App\Models\StudioHoliday;

class StudioHolidayController extends Controller
{
    /**
     * Every studio-wide closed day, earliest first — one entry here closes
     * the whole studio for all professionals on that date.
     */
    public function index()
    {
        $holidays = StudioHoliday::query()
            ->orderBy('date')
            ->get()
            ->map(fn (StudioHoliday $holiday) => $this->shape($holiday))
            ->values();

        return response()->json(['data' => $holidays]);
    }

    public function store(StoreStudioHolidayRequest $request)
    {
        $holiday = StudioHoliday::create($request->validated());

        return response()->json(['data' => $this->shape($holiday)], 201);
    }

    public function update(UpdateStudioHolidayRequest $request, StudioHoliday $studioHoliday)
    {
        $studioHoliday->update($request->validated());

        return response()->json(['data' => $this->shape($studioHoliday->fresh())]);
    }

    public function destroy(StudioHoliday $studioHoliday)
    {
        $studioHoliday->delete();

        return response()->noContent();
    }

    private function shape(StudioHoliday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
        ];
    }
}
