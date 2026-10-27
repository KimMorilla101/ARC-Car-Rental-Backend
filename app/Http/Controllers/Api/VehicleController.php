<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\ListVehiclesRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Support\BusinessSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * The renter-facing catalog. Only active, fully priced vehicles are ever returned.
 */
class VehicleController extends Controller
{
    private const HOME_FEED_SIZE = 6;

    public function index(ListVehiclesRequest $request, BusinessSettings $settings): AnonymousResourceCollection
    {
        // With both dates, availability counts only units free for that window.
        $pickup = $request->validated('pickup_at') ? Carbon::parse($request->validated('pickup_at')) : null;
        $return = $pickup && $request->validated('return_at') ? Carbon::parse($request->validated('return_at')) : null;
        $window = $return ? [$pickup, $return, $settings->turnaroundBufferMinutes()] : [];

        $query = Vehicle::query()->visibleToRenters()->withCatalogData(...$window);

        if ($search = $request->validated('search')) {
            $query->where('name', 'like', '%'.addcslashes($search, '\\%_').'%');
        }

        foreach (['category', 'transmission', 'fuel'] as $field) {
            if ($value = $request->validated($field)) {
                $query->where($field, $value);
            }
        }

        if ($minSeats = $request->validated('min_seats')) {
            $query->where('seats', '>=', (int) $minSeats);
        }

        if ($request->validated('max_daily_rate') !== null) {
            $query->where('daily_rate', '<=', $request->validated('max_daily_rate'));
        }

        if ($request->boolean('available_only')) {
            $query->withAvailableUnits(...$window);
        }

        $this->applySort($query, $request->validated('sort') ?? 'recommended');

        return VehicleResource::collection($query->get())->additional([
            'meta' => ['fleet_size' => Vehicle::query()->visibleToRenters()->count()],
        ]);
    }

    public function show(int $vehicle): VehicleResource
    {
        return new VehicleResource(
            Vehicle::query()->visibleToRenters()->withCatalogData()->findOrFail($vehicle),
        );
    }

    /**
     * Temporary rules until ARC defines its own: featured = popular, recommended = vehicles with a
     * unit available, newest first. There is no personalisation.
     */
    public function homeFeed(): JsonResponse
    {
        $base = fn () => Vehicle::query()->visibleToRenters()->withCatalogData()->latest()->latest('id')->limit(self::HOME_FEED_SIZE);

        return response()->json([
            'data' => [
                'featured' => VehicleResource::collection($base()->where('is_popular', true)->get()),
                'recommended' => VehicleResource::collection($base()->withAvailableUnits()->get()),
            ],
        ]);
    }

    /**
     * There are no ratings yet, so "rating" falls back to the recommended order.
     *
     * @param  Builder<Vehicle>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('daily_rate'),
            'price_desc' => $query->orderByDesc('daily_rate'),
            default => $query->orderByDesc('is_popular')->orderBy('name'),
        };

        $query->orderBy('id');
    }
}
