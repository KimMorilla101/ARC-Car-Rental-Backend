<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\StoreBranchRequest;
use App\Http\Requests\ReferenceData\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Branches are deactivated (is_active = false) rather than deleted.
 */
class BranchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage-reference-data');

        return BranchResource::collection(Branch::query()->orderBy('name')->get());
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        return (new BranchResource(Branch::create($request->validated())->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $branch->update($request->validated());

        return new BranchResource($branch);
    }
}
