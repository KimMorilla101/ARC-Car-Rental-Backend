<?php

namespace Tests\Feature\Bookings;

use App\Enums\BookingStatus;
use App\Enums\RequirementStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Review fixes: locked uploads, explicit timezones, column limits and protected document records.
 */
class BookingSafeguardsTest extends TestCase
{
    use BookingTestHelpers, RefreshDatabase;

    public function test_upload_after_staff_confirmation_is_refused_and_verified_documents_stay(): void
    {
        Storage::fake('local');
        $booking = Booking::factory()->verified()->create();
        $this->actingAs(User::factory()->staff()->create(), 'sanctum')
            ->postJson("/api/staff/bookings/{$booking->id}/confirm")->assertOk();

        $this->actingAs($booking->user, 'sanctum')
            ->post("/api/bookings/{$booking->id}/requirements", ['type' => 'valid_id', 'file' => $this->phonePhoto()], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(RequirementStatus::Verified, $booking->requirements()->where('type', 'valid_id')->first()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_failed_database_write_leaves_no_orphaned_file(): void
    {
        Storage::fake('local');
        $booking = Booking::factory()->create();
        BookingRequirement::saving(fn () => throw new RuntimeException('Simulated database failure'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($booking->user, 'sanctum')
                ->post("/api/bookings/{$booking->id}/requirements", ['type' => 'valid_id', 'file' => $this->phonePhoto()]);
            $this->fail('The simulated failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database failure', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_dates_without_a_timezone_are_rejected(): void
    {
        $this->setUpBookableWorld();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $day = now('Asia/Manila')->addDays(2)->format('Y-m-d');

        $this->postJson('/api/bookings/quote', $this->quotePayload(['pickup_at' => "{$day} 09:00:00", 'return_at' => "{$day} 09:00:00"]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.pickup_at.0', 'Include a timezone with the date, e.g. 2026-11-05T09:00:00+08:00.');
    }

    public function test_dates_with_a_manila_offset_are_stored_in_utc(): void
    {
        $this->setUpBookableWorld();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $pickup = now('Asia/Manila')->addDays(2)->setTime(9, 0);

        $this->postJson('/api/bookings', $this->bookingPayload([
            'pickup_at' => $pickup->toIso8601String(),
            'return_at' => $pickup->copy()->addDays(3)->toIso8601String(),
        ]))->assertCreated()->assertJsonPath('data.pickup_at', $pickup->toIso8601String());

        $this->assertStringEndsWith('01:00:00', DB::table('bookings')->value('pickup_at'));
    }

    public function test_amounts_beyond_the_price_columns_are_refused_instead_of_crashing(): void
    {
        $this->setUpBookableWorld();
        $this->actingAs(User::factory()->create(), 'sanctum');

        // 2,500 months × ₱40,000 = ₱100,000,000, above the ₱99,999,999.99 the columns can hold.
        $this->postJson('/api/bookings', $this->bookingPayload($this->window(75000)))
            ->assertUnprocessable()
            ->assertJsonPath('errors.return_at.0', 'This rental is too long to book online. Please contact ARC.');

        // 72,990 days fits once rental_days is a full integer column.
        $this->postJson('/api/bookings', $this->bookingPayload($this->window(72990)))
            ->assertCreated()
            ->assertJsonPath('data.rental_days', 72990);
    }

    public function test_a_booking_with_documents_cannot_be_deleted_by_accident(): void
    {
        $booking = Booking::factory()->create();

        try {
            $booking->delete();
            $this->fail('The booking row was deleted.');
        } catch (QueryException) {
            // Refused by the foreign key.
        }

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
        $this->assertSame(4, $booking->requirements()->count());
    }
}
