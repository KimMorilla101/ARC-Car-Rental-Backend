<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\StorePaymentOptionRequest;
use App\Http\Requests\ReferenceData\UpdatePaymentOptionRequest;
use App\Http\Resources\PaymentOptionResource;
use App\Models\PaymentOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Admin only. Options are deactivated (is_active = false) rather than deleted.
 */
class PaymentOptionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage-business-config');

        return PaymentOptionResource::collection(PaymentOption::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(StorePaymentOptionRequest $request): JsonResponse
    {
        return (new PaymentOptionResource(PaymentOption::create($request->validated())->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePaymentOptionRequest $request, PaymentOption $option): PaymentOptionResource
    {
        $option->update($request->validated());

        return new PaymentOptionResource($option);
    }
}
