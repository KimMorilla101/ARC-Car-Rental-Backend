<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The renter's own account, as the mobile app expects it. Only whitelisted fields are exposed;
 * never fall back to parent::toArray(), which would leak every column added later.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, name: string, email: string, role: string, phone: ?string, address: ?string, avatar_url: ?string, trust_score: ?int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Lets a staff client (e.g. the admin website) tell staff from renters; the app ignores it.
            'role' => $this->effectiveRole()->value,
            'phone' => $this->phone,
            'address' => $this->address,
            'avatar_url' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'trust_score' => $this->trust_score,
        ];
    }
}
