<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_accounts_are_renters_even_if_a_role_is_sent(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'device_name' => 'test',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('user.role', 'renter');

        $this->assertSame(UserRole::Renter, User::sole()->role);
    }

    public function test_current_user_endpoint_reports_the_role(): void
    {
        $staff = User::factory()->staff()->create();

        $this->withToken($staff->createToken('t')->plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.role', 'staff');
    }

    public function test_command_sets_the_role_of_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'kim@example.com']);

        $this->artisan('arc:set-role', ['email' => 'Kim@Example.com', 'role' => 'staff'])
            ->expectsOutput('kim@example.com is now staff.')
            ->assertSuccessful();

        $this->assertSame(UserRole::Staff, $user->fresh()->role);
    }

    public function test_command_rejects_an_unknown_email_and_creates_nothing(): void
    {
        $this->artisan('arc:set-role', ['email' => 'nobody@example.com', 'role' => 'admin'])
            ->expectsOutput('No account uses that email address.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_an_invalid_role(): void
    {
        $user = User::factory()->create();

        $this->artisan('arc:set-role', ['email' => $user->email, 'role' => 'superuser'])->assertFailed();

        $this->assertSame(UserRole::Renter, $user->fresh()->role);
    }
}
