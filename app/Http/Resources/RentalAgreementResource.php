<?php

namespace App\Http\Resources;

use App\Models\RentalAgreement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renters get the contract shape ({version, title, sections}); staff routes also see the draft or
 * published state.
 *
 * @mixin RentalAgreement
 */
class RentalAgreementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = config('app.api_timezone');

        return [
            $this->mergeWhen($request->routeIs('staff.*'), fn () => [
                'id' => $this->id,
                'is_published' => $this->isPublished(),
                'published_at' => $this->published_at?->setTimezone($timezone)->toIso8601String(),
                'created_at' => $this->created_at?->setTimezone($timezone)->toIso8601String(),
            ]),
            'version' => $this->version,
            'title' => $this->title,
            'sections' => array_map(fn (array $section) => [
                'title' => $section['title'],
                'body' => $section['body'],
            ], $this->sections),
        ];
    }
}
