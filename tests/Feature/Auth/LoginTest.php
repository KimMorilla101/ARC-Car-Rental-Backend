<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_renter_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'Juan@Example.com',
            'password' => 'password',
            'device_name' => 'ARC Ride iPhone',
        ]);

        $response->assertOk()
            ->assertExactJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'role', 'phone', 'address', 'avatar_url', 'trust_score'],
            ])
            ->assertJsonPath('user.id', $user->id);

        $token = $user->tokens()->sole();
        $this->assertSame('ARC Ride iPhone', $token->name);
        $this->assertTrue($token->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
    }

    public function test_wrong_password_returns_the_generic_credentials_error(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'juan@example.com',
            'password' => 'wrong-password',
            'device_name' => 'test',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.')
            ->assertJsonMissingPath('token');
    }

    public function test_unknown_email_returns_the_same_error_as_a_wrong_password(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
            'device_name' => 'test',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'These credentials do not match our records.',
                'errors' => ['email' => ['These credentials do not match our records.']],
            ]);
    }

    public function test_login_requires_email_password_and_device_name(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_repeated_failed_logins_are_throttled(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);
        $attempt = ['email' => 'juan@example.com', 'password' => 'wrong-password', 'device_name' => 'test'];

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', $attempt)->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', $attempt)->assertTooManyRequests();
    }
}
