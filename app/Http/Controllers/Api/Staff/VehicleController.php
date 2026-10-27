<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\StoreVehicleRequest;
use App\Http\Requests\Vehicles\UpdateVehicleRequest;
use App\Http\Resources\StaffVehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Fleet management for staff and admins. No admin pages: any staff client calls these endpoints.
 */
class VehicleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Vehicle::class);

        return StaffVehicleResource::collection(
            Vehicle::query()->withCatalogData()->with('units')->latest()->latest('id')->get(),
        );
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = Vehicle::create($request->validated());

        return $this->present($vehicle)->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): StaffVehicleResource
    {
        Gate::authorize('view', $vehicle);

        return $this->present($vehicle);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): StaffVehicleResource
    {
        $vehicle->update($request->validated());

        return $this->present($vehicle);
    }

    /**
     * Soft delete: the vehicle disappears from the catalog but stays available for booking history.
     */
    public function destroy(Vehicle $vehicle): Response
    {
        Gate::authorize('delete', $vehicle);

        $vehicle->delete();

        return response()->noContent();
    }

    private function present(Vehicle $vehicle): StaffVehicleResource
    {
        return new StaffVehicleResource(
            Vehicle::query()->withCatalogData()->with('units')->findOrFail($vehicle->id),
        );
    }
}
