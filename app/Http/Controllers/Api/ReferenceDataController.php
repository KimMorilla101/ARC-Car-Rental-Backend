<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\DeliveryZoneResource;
use App\Http\Resources\PaymentOptionResource;
use App\Http\Resources\RentalAgreementResource;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\PaymentOption;
use App\Models\RentalAgreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Booking reference data for renters. Only active or published records are returned; nothing is
 * filled in when ARC has not configured it yet.
 */
class ReferenceDataController extends Controller
{
    public function locations(): JsonResponse
    {
        return response()->json([
            'data' => [
                'branches' => BranchResource::collection(Branch::query()->active()->orderBy('name')->get()),
                'delivery_zones' => DeliveryZoneResource::collection(DeliveryZone::query()->active()->orderBy('name')->get()),
            ],
        ]);
    }

    public function paymentMethods(): AnonymousResourceCollection
    {
        return PaymentOptionResource::collection(
            PaymentOption::query()->active()->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function agreement(): RentalAgreementResource|JsonResponse
    {
        $agreement = RentalAgreement::current();

        if ($agreement === null) {
            return response()->json(['message' => 'The rental agreement has not been published yet.'], 404);
        }

        return new RentalAgreementResource($agreement);
    }
}
