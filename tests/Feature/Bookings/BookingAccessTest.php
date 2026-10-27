<?php

namespace Tests\Feature\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_endpoints_require_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->getJson('/api/bookings')->assertUnauthorized();
        $this->getJson("/api/bookings/{$booking->id}")->assertUnauthorized();
        $this->getJson("/api/bookings/{$booking->id}/requirements/valid_id/file")->assertUnauthorized();
    }

    public function test_renters_only_list_their_own_bookings(): void
    {
        $renter = User::factory()->create();
        $mine = Booking::factory()->for($renter)->create();
        Booking::factory()->create();

        $this->actingAs($renter, 'sanctum')
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_another_renters_booking_is_not_found(): void
    {
        $theirs = Booking::factory()->create();
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson("/api/bookings/{$theirs->id}")->assertNotFound();
        $this->getJson('/api/bookings/999999')->assertNotFound();
    }

    public function test_status_filters_match_the_app(): void
    {
        $renter = User::factory()->create();
        $pending = Booking::factory()->for($renter)->create();
        $active = Booking::factory()->for($renter)->status(BookingStatus::Active)->create();
        $cancelled = Booking::factory()->for($renter)->status(BookingStatus::Cancelled)->create();
        $this->actingAs($renter, 'sanctum');

        $ids = fn (string $filter) => collect($this->getJson('/api/bookings?status='.$filter)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$pending->id], $ids('upcoming'));
        $this->assertSame([$active->id], $ids('active'));
        $this->assertSame([$cancelled->id], $ids('cancelled'));
        $this->assertSame([], $ids('completed'));
        $this->getJson('/api/bookings?status=everything')->assertUnprocessable();
    }

    public function test_active_booking_past_its_return_time_is_reported_as_return_due(): void
    {
        $renter = User::factory()->create();
        $booking = Booking::factory()->for($renter)->status(BookingStatus::Active)->create([
            'pickup_at' => now()->subDays(3), 'return_at' => now()->subHour(),
        ]);

        $this->actingAs($renter, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'return_due');

        $this->assertSame(BookingStatus::Active, $booking->fresh()->status);
    }

    public function test_private_documents_are_served_only_to_the_renter_and_staff(): void
    {
        Storage::fake('local');
        $renter = User::factory()->create();
        $booking = Booking::factory()->for($renter)->create();
        $path = "bookings/{$booking->id}/requirements/license.jpg";
        Storage::disk('local')->put($path, 'image-bytes');
        $booking->requirements()->where('type', 'drivers_license')->update(['file_path' => $path, 'status' => 'pending_verification']);
        $url = "/api/bookings/{$booking->id}/requirements/drivers_license/file";

        $this->actingAs(User::factory()->create(), 'sanctum')->get($url)->assertNotFound();

        $this->actingAs($renter, 'sanctum')->get($url)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->get($url)->headers->get('Cache-Control'));

        $this->actingAs(User::factory()->staff()->create(), 'sanctum')->get($url)->assertOk();

        $this->actingAs($renter, 'sanctum')->get("/api/bookings/{$booking->id}/requirements/valid_id/file")->assertNotFound();
        $this->get("/api/bookings/{$booking->id}/requirements/not_a_type/file")->assertNotFound();
        $this->get("/api/bookings/{$booking->id}/payment-proof/file")->assertNotFound();
    }
}
