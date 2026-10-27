<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\StoreVehicleUnitRequest;
use App\Http\Requests\Vehicles\UpdateVehicleUnitRequest;
use App\Http\Resources\VehicleUnitResource;
use App\Models\Vehicle;
use App\Models\VehicleUnit;
use Illuminate\Http\JsonResponse;

/**
 * Physical cars of a vehicle model. Units are retired (status "retired") rather than deleted.
 */
class VehicleUnitController extends Controller
{
    public function store(StoreVehicleUnitRequest $request, Vehicle $vehicle): JsonResponse
    {
        $unit = $vehicle->units()->create($request->validated());

        return (new VehicleUnitResource($unit->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateVehicleUnitRequest $request, VehicleUnit $unit): VehicleUnitResource
    {
        $unit->update($request->validated());

        return new VehicleUnitResource($unit);
    }
}
