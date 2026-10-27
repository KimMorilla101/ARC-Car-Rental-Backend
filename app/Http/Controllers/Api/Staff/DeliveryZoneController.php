<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\StoreDeliveryZoneRequest;
use App\Http\Requests\ReferenceData\UpdateDeliveryZoneRequest;
use App\Http\Resources\DeliveryZoneResource;
use App\Models\DeliveryZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Delivery zones are deactivated (is_active = false) rather than deleted.
 */
class DeliveryZoneController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage-reference-data');

        return DeliveryZoneResource::collection(DeliveryZone::query()->orderBy('name')->get());
    }

    public function store(StoreDeliveryZoneRequest $request): JsonResponse
    {
        return (new DeliveryZoneResource(DeliveryZone::create($request->validated())->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDeliveryZoneRequest $request, DeliveryZone $zone): DeliveryZoneResource
    {
        $zone->update($request->validated());

        return new DeliveryZoneResource($zone);
    }
}
