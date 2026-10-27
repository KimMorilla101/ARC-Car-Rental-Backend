<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'device_name' => 'ARC Ride Pixel 8',
        ], $overrides);
    }

    public function test_renter_can_register_and_receives_a_working_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validPayload());

        $response->assertCreated()
            ->assertExactJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'role', 'phone', 'address', 'avatar_url', 'trust_score'],
            ])
            ->assertJsonPath('user.email', 'juan@example.com')
            ->assertJsonPath('user.phone', '09171234567')
            ->assertJsonPath('user.trust_score', null);

        $user = User::firstWhere('email', 'juan@example.com');
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));
        $this->assertNotSame('secret-pass-1', $user->password);
        $this->assertSame('ARC Ride Pixel 8', $user->tokens()->first()->name);

        $this->withToken($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_response_never_contains_the_password_or_its_hash(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validPayload());

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret-pass-1', $body);
        $this->assertStringNotContainsString(User::first()->password, $body);
        $response->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
    }

    public function test_email_and_phone_are_normalized(): void
    {
        $this->postJson('/api/auth/register', $this->validPayload([
            'email' => '  Juan@Example.COM ',
            'phone' => '0917-123 4567',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'juan@example.com', 'phone' => '09171234567']);
    }

    public function test_renter_cannot_set_staff_managed_fields(): void
    {
        $this->postJson('/api/auth/register', $this->validPayload([
            'trust_score' => 100,
            'avatar_path' => 'avatars/someone-else.jpg',
        ]))->assertCreated();

        $user = User::first();
        $this->assertNull($user->trust_score);
        $this->assertNull($user->avatar_path);
    }

    public function test_duplicate_email_is_rejected_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $this->postJson('/api/auth/register', $this->validPayload(['email' => 'JUAN@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'password', 'device_name']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->postJson('/api/auth/register', $this->validPayload([
            'email' => 'not-an-email',
            'phone' => '12345',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'password'])
            ->assertJsonPath('errors.phone.0', 'Enter a valid mobile number, e.g. 09171234567.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/auth/register', [])->assertTooManyRequests();
    }
}
