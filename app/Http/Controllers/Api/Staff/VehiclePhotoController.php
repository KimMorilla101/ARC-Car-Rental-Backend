<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\StoreVehiclePhotoRequest;
use App\Http\Resources\StaffVehicleResource;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\ImageSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public vehicle photos. Identity documents and payment proofs never use this controller or disk.
 */
class VehiclePhotoController extends Controller
{
    public function store(StoreVehiclePhotoRequest $request, Vehicle $vehicle, ImageSanitizer $sanitizer): JsonResponse
    {
        $image = $sanitizer->sanitize($request->file('photo'), 'photo');

        // A random name: the uploaded filename is never used on disk.
        $path = "vehicles/{$vehicle->id}/".Str::random(40).".{$image['extension']}";
        Storage::disk('public')->put($path, $image['contents']);

        $vehicle->photos()->create([
            'path' => $path,
            'sort_order' => (int) $vehicle->photos()->max('sort_order') + 1,
        ]);

        return (new StaffVehicleResource(Vehicle::query()->withCatalogData()->with('units')->findOrFail($vehicle->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(VehiclePhoto $photo): Response
    {
        Gate::authorize('update', $photo->vehicle);

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return response()->noContent();
    }
}
