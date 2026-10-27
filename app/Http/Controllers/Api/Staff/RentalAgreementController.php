<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\StoreRentalAgreementRequest;
use App\Http\Requests\ReferenceData\UpdateRentalAgreementRequest;
use App\Http\Resources\RentalAgreementResource;
use App\Models\RentalAgreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Staff write drafts; only admins publish. The newest published version is what renters accept.
 */
class RentalAgreementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage-reference-data');

        return RentalAgreementResource::collection(RentalAgreement::query()->latest()->latest('id')->get());
    }

    public function store(StoreRentalAgreementRequest $request): JsonResponse
    {
        return (new RentalAgreementResource(RentalAgreement::create($request->validated())->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRentalAgreementRequest $request, RentalAgreement $agreement): RentalAgreementResource
    {
        $agreement->update($request->validated());

        return new RentalAgreementResource($agreement);
    }

    /**
     * @throws ValidationException
     */
    public function publish(RentalAgreement $agreement): RentalAgreementResource
    {
        Gate::authorize('manage-business-config');

        if ($agreement->isPublished()) {
            throw ValidationException::withMessages(['version' => 'This version is already published.']);
        }

        $agreement->forceFill(['published_at' => now()])->save();

        return new RentalAgreementResource($agreement);
    }
}
