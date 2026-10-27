<?php

namespace Tests\Feature\Bookings;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\PaymentOption;
use App\Models\RentalAgreement;
use App\Models\Vehicle;
use App\Models\VehicleUnit;
use App\Support\BusinessSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Builds the reference data a booking needs. Test database only.
 */
trait BookingTestHelpers
{
    protected Vehicle $vehicle;

    protected Branch $branch;

    protected DeliveryZone $zone;

    protected function setUpBookableWorld(int $units = 1, ?float $carWashFee = 350): void
    {
        if ($carWashFee !== null) {
            app(BusinessSettings::class)->update(['car_wash_fee' => $carWashFee]);
        }

        $this->vehicle = Vehicle::factory()->active(2000)->create(['monthly_rate' => 40000]);
        VehicleUnit::factory()->for($this->vehicle)->count($units)->create();
        $this->branch = Branch::factory()->create(['name' => 'Main Branch']);
        $this->zone = DeliveryZone::factory()->create(['fee' => 500]);
        RentalAgreement::factory()->published()->create(['version' => 'v1']);
        PaymentOption::factory()->create();
        PaymentOption::factory()->bankTransfer()->create();
    }

    /**
     * A window starting $daysAhead days from today at 09:00 Manila time, sent in UTC like the app.
     *
     * @return array{pickup_at: string, return_at: string}
     */
    protected function window(int $days = 3, int $daysAhead = 1, int $hour = 9): array
    {
        $pickup = Carbon::today(config('app.api_timezone'))->addDays($daysAhead)->setTime($hour, 0);

        return [
            'pickup_at' => $pickup->copy()->utc()->toIso8601ZuluString(),
            'return_at' => $pickup->copy()->addDays($days)->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function quotePayload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->vehicle->id,
            ...$this->window(),
            'delivery_method' => 'shop_pickup',
            'branch_id' => $this->branch->id,
            'delivery_zone_id' => null,
            'delivery_address' => null,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge($this->quotePayload(), [
            'destination' => 'Samal Island',
            'payment_method' => PaymentMethod::Cash->value,
            'agreement_version' => 'v1',
            'agreement_accepted' => true,
        ], $overrides);
    }

    /**
     * A real JPEG carrying an EXIF segment with a recognisable marker, like a phone photo with GPS.
     */
    protected function phonePhoto(string $name = 'photo.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $payload = "Exif\0\0SECRET-GPS-MARKER";

        return UploadedFile::fake()->createWithContent($name, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2));
    }
}
