<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\UpdateBusinessSettingsRequest;
use App\Support\BusinessSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Staff can read the settings; only admins change them.
 */
class BusinessSettingController extends Controller
{
    public function show(BusinessSettings $settings): JsonResponse
    {
        Gate::authorize('manage-reference-data');

        return response()->json(['data' => $settings->all()]);
    }

    public function update(UpdateBusinessSettingsRequest $request, BusinessSettings $settings): JsonResponse
    {
        $settings->update($request->validated());

        return response()->json(['data' => $settings->all()]);
    }
}
