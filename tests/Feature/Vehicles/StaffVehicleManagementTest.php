<?php

namespace Tests\Feature\Vehicles;

use App\Enums\VehicleStatus;
use App\Enums\VehicleUnitStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffVehicleManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function vehiclePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Honda BR-V 2024',
            'category' => 'SUV',
            'transmission' => 'Automatic',
            'fuel' => 'Gasoline',
            'seats' => 7,
            'doors' => 5,
            'features' => ['Rear camera'],
        ], $overrides);
    }

    /**
     * A real JPEG with an APP1/EXIF segment carrying a recognisable marker, like a phone photo
     * that embeds GPS data.
     */
    private function jpegWithExifMarker(): UploadedFile
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $payload = "Exif\0\0SECRET-GPS-MARKER";
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return UploadedFile::fake()->createWithContent('phone-photo.jpg', substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    }

    public function test_renters_cannot_use_any_staff_endpoint(): void
    {
        $vehicle = Vehicle::factory()->create();
        $unit = VehicleUnit::factory()->for($vehicle)->create();
        $photo = $vehicle->photos()->create(['path' => 'vehicles/x.jpg', 'sort_order' => 1]);
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson('/api/staff/vehicles')->assertForbidden();
        $this->getJson("/api/staff/vehicles/{$vehicle->id}")->assertForbidden();
        $this->postJson('/api/staff/vehicles', $this->vehiclePayload())->assertForbidden();
        $this->postJson('/api/staff/vehicles', [])->assertForbidden();
        $this->putJson("/api/staff/vehicles/{$vehicle->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/staff/vehicles/{$vehicle->id}")->assertForbidden();
        $this->postJson("/api/staff/vehicles/{$vehicle->id}/units", ['label' => 'U', 'plate_number' => 'AAA 1111'])->assertForbidden();
        $this->patchJson("/api/staff/units/{$unit->id}", ['status' => 'retired'])->assertForbidden();
        $this->postJson("/api/staff/vehicles/{$vehicle->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 400, 300)])->assertForbidden();
        $this->deleteJson("/api/staff/photos/{$photo->id}")->assertForbidden();

        $this->assertSame('Test', substr($vehicle->fresh()->name, 0, 4));
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseCount('vehicle_photos', 1);
    }

    public function test_staff_endpoints_require_authentication(): void
    {
        $this->getJson('/api/staff/vehicles')->assertUnauthorized();
        $this->postJson('/api/staff/vehicles', $this->vehiclePayload())->assertUnauthorized();
    }

    public function test_staff_can_create_an_unpriced_draft(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');

        $this->postJson('/api/staff/vehicles', $this->vehiclePayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.rates', ['hourly' => null, 'daily' => null, 'monthly' => null])
            ->assertJsonPath('data.units', [])
            ->assertJsonPath('data.photos', []);

        $this->assertDatabaseHas('vehicles', ['name' => 'Honda BR-V 2024', 'status' => 'draft', 'daily_rate' => null]);
    }

    public function test_activation_requires_all_rates(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');

        $this->postJson('/api/staff/vehicles', $this->vehiclePayload(['status' => 'active', 'daily_rate' => 2500]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_rate', 'monthly_rate'])
            ->assertJsonMissingValidationErrors(['daily_rate']);

        $vehicle = Vehicle::factory()->create();
        $this->putJson("/api/staff/vehicles/{$vehicle->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_rate', 'daily_rate', 'monthly_rate']);

        $this->putJson("/api/staff/vehicles/{$vehicle->id}", ['hourly_rate' => 300, 'daily_rate' => 2500, 'monthly_rate' => 45000, 'status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.rates', ['hourly' => 300, 'daily' => 2500, 'monthly' => 45000]);
    }

    public function test_a_rate_cannot_be_cleared_while_the_vehicle_is_active(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $vehicle = Vehicle::factory()->active()->create();

        $this->putJson("/api/staff/vehicles/{$vehicle->id}", ['daily_rate' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['daily_rate']);

        $this->putJson("/api/staff/vehicles/{$vehicle->id}", ['daily_rate' => null, 'status' => 'inactive'])->assertOk();
        $this->assertSame(VehicleStatus::Inactive, $vehicle->fresh()->status);
    }

    public function test_vehicle_fields_are_validated(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');

        $this->postJson('/api/staff/vehicles', [
            'category' => 'Spaceship', 'transmission' => 'CVT', 'fuel' => 'Coal', 'seats' => 0,
            'hourly_rate' => -5, 'daily_rate' => 'free', 'monthly_rate' => 10.555, 'features' => ['A', 'A'], 'status' => 'published',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name', 'category', 'transmission', 'fuel', 'seats', 'hourly_rate', 'daily_rate', 'monthly_rate', 'features.0', 'status',
            ]);

        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_staff_list_includes_drafts_units_and_photos(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $draft = Vehicle::factory()->create();
        VehicleUnit::factory()->for($draft)->create(['plate_number' => 'ABC 1234']);
        Vehicle::factory()->active()->create();

        $this->getJson('/api/staff/vehicles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['plate_number' => 'ABC 1234', 'status' => 'available']);

        $this->getJson("/api/staff/vehicles/{$draft->id}")->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_only_admins_can_delete_and_deletion_is_soft(): void
    {
        $vehicle = Vehicle::factory()->active()->create();

        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $this->deleteJson("/api/staff/vehicles/{$vehicle->id}")->assertForbidden();
        $this->assertNotSoftDeleted($vehicle);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $this->deleteJson("/api/staff/vehicles/{$vehicle->id}")->assertNoContent();
        $this->assertSoftDeleted($vehicle);
        $this->getJson("/api/vehicles/{$vehicle->id}")->assertNotFound();
    }

    public function test_units_can_be_added_and_updated(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $vehicle = Vehicle::factory()->create();

        $unitId = $this->postJson("/api/staff/vehicles/{$vehicle->id}/units", ['label' => 'Honda BR-V Unit 01', 'plate_number' => ' abc   1234 '])
            ->assertCreated()
            ->assertJsonPath('data.plate_number', 'ABC 1234')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->json('data.id');

        $this->patchJson("/api/staff/units/{$unitId}", ['status' => 'maintenance'])
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance');

        $this->patchJson("/api/staff/units/{$unitId}", ['plate_number' => 'ABC 1234'])->assertOk();
        $this->patchJson("/api/staff/units/{$unitId}", ['status' => 'rented'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_plate_numbers_are_unique(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        VehicleUnit::factory()->create(['plate_number' => 'ABC 1234']);
        $vehicle = Vehicle::factory()->create();

        $this->postJson("/api/staff/vehicles/{$vehicle->id}/units", ['label' => 'Second', 'plate_number' => 'abc 1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plate_number']);
    }

    public function test_photo_upload_strips_metadata_and_is_publicly_stored(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $vehicle = Vehicle::factory()->create();

        $original = $this->jpegWithExifMarker();
        $this->assertStringContainsString('SECRET-GPS-MARKER', $original->getContent());

        $response = $this->postJson("/api/staff/vehicles/{$vehicle->id}/photos", ['photo' => $original])
            ->assertCreated()
            ->assertJsonCount(1, 'data.photos');

        $path = $vehicle->photos()->sole()->path;
        $this->assertMatchesRegularExpression('#^vehicles/'.$vehicle->id.'/[A-Za-z0-9]{40}\.jpg$#', $path);
        Storage::disk('public')->assertExists($path);

        $stored = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('SECRET-GPS-MARKER', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertSame([400, 300], array_slice(getimagesizefromstring($stored), 0, 2));
        $this->assertStringEndsWith($path, $response->json('data.photos.0.url'));
    }

    public function test_png_photos_are_accepted(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $vehicle = Vehicle::factory()->create();

        $this->postJson("/api/staff/vehicles/{$vehicle->id}/photos", ['photo' => UploadedFile::fake()->image('car.png', 400, 300)])
            ->assertCreated();

        $this->assertStringEndsWith('.png', $vehicle->photos()->sole()->path);
    }

    public function test_unsafe_or_unsupported_photos_are_rejected(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $vehicle = Vehicle::factory()->create();
        $upload = fn (UploadedFile $file) => $this->postJson("/api/staff/vehicles/{$vehicle->id}/photos", ['photo' => $file]);

        $upload(UploadedFile::fake()->create('car.heic', 100, 'image/heic'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Only JPEG or PNG photos are accepted. HEIC photos are not supported; please convert them to JPEG first.');
        $upload(UploadedFile::fake()->create('car.pdf', 100, 'application/pdf'))->assertUnprocessable();
        $upload(UploadedFile::fake()->createWithContent('car.jpg', '<?php echo "not an image";'))->assertUnprocessable();
        $upload(UploadedFile::fake()->image('big.jpg', 400, 300)->size(6000))->assertUnprocessable();
        $upload(UploadedFile::fake()->image('tiny.jpg', 50, 50))->assertUnprocessable();

        $this->assertDatabaseCount('vehicle_photos', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_deleting_a_photo_removes_the_file(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');
        $vehicle = Vehicle::factory()->create();
        $this->postJson("/api/staff/vehicles/{$vehicle->id}/photos", ['photo' => UploadedFile::fake()->image('car.jpg', 400, 300)])->assertCreated();
        $photo = $vehicle->photos()->sole();

        $this->deleteJson("/api/staff/photos/{$photo->id}")->assertNoContent();

        Storage::disk('public')->assertMissing($photo->path);
        $this->assertDatabaseCount('vehicle_photos', 0);
    }

    public function test_unit_status_controls_renter_availability(): void
    {
        $vehicle = Vehicle::factory()->active()->create();
        $unit = VehicleUnit::factory()->for($vehicle)->create();
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');

        $this->getJson("/api/vehicles/{$vehicle->id}")->assertJsonPath('data.available_units', 1);
        $this->patchJson("/api/staff/units/{$unit->id}", ['status' => VehicleUnitStatus::Maintenance->value])->assertOk();
        $this->getJson("/api/vehicles/{$vehicle->id}")->assertJsonPath('data.available_units', 0);
    }
}
