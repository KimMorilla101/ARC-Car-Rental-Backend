<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_MESSAGE = 'If an account exists for that email address, we have sent a password reset link.';

    public function test_reset_link_is_emailed_to_an_existing_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'juan@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'Juan@example.com'])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password/'.$notification->token)
                && str_contains($url, 'email=juan%40example.com');
        });
    }

    public function test_reset_link_uses_app_url_even_when_the_host_header_is_forged(): void
    {
        Notification::fake();
        config(['app.url' => 'http://arc-api.test:8000']);
        $user = User::factory()->create(['email' => 'juan@example.com']);

        $this->withHeader('Host', 'attacker.example')
            ->postJson('/api/auth/forgot-password', ['email' => 'juan@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://arc-api.test:8000/reset-password/')
                && ! str_contains($url, 'attacker.example');
        });
    }

    public function test_unknown_email_gets_the_same_response_and_no_email(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_the_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'juan@example.com'])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'juan@example.com'])->assertTooManyRequests();
    }

    public function test_reset_page_renders_for_the_emailed_link(): void
    {
        $this->get('/reset-password/some-token?email=juan@example.com')
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('value="some-token"', false)
            ->assertSee('juan@example.com');
    }

    public function test_valid_reset_changes_the_password_and_signs_out_every_device(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);
        $user->createToken('phone');
        $user->createToken('tablet');
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'juan@example.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk()->assertSee('Password updated');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/auth/login', ['email' => 'juan@example.com', 'password' => 'password', 'device_name' => 'x'])
            ->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'juan@example.com', 'password' => 'brand-new-pass', 'device_name' => 'x'])
            ->assertOk();
    }

    public function test_invalid_token_does_not_change_the_password(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);

        $this->from('/reset-password/bad-token')->post('/reset-password', [
            'token' => 'bad-token',
            'email' => 'juan@example.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('/reset-password/bad-token')->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_emailed_link_opens_a_working_reset_page(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'juan@example.com']);
        $this->postJson('/api/auth/forgot-password', ['email' => 'juan@example.com'])->assertOk();

        $link = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$link) {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });
        $parts = parse_url($link);
        parse_str($parts['query'], $query);
        $token = basename($parts['path']);

        $this->get($parts['path'].'?'.$parts['query'])
            ->assertOk()
            ->assertSee('value="'.$token.'"', false)
            ->assertSee('juan@example.com');

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $query['email'],
            'password' => 'from-the-email-link',
            'password_confirmation' => 'from-the-email-link',
        ])->assertOk()->assertSee('Password updated');

        $this->assertTrue(Hash::check('from-the-email-link', $user->fresh()->password));
    }

    public function test_reset_token_can_only_be_used_once(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);
        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => 'juan@example.com', 'password' => 'first-new-pass', 'password_confirmation' => 'first-new-pass'];

        $this->post('/reset-password', $payload)->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            ...$payload,
            'password' => 'second-new-pass',
            'password_confirmation' => 'second-new-pass',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('first-new-pass', $user->fresh()->password));
    }

    public function test_reset_token_expires_after_sixty_minutes(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => 'juan@example.com',
            'password' => 'too-late-pass',
            'password_confirmation' => 'too-late-pass',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_form_submissions_are_rate_limited(): void
    {
        $attempt = ['token' => 'guess', 'email' => 'juan@example.com', 'password' => 'guess-pass-1', 'password_confirmation' => 'guess-pass-1'];

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/reset-password', $attempt)->assertRedirect();
        }

        $this->post('/reset-password', $attempt)->assertTooManyRequests();
    }

    public function test_reset_validates_the_new_password(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'juan@example.com',
            'password' => 'short',
            'password_confirmation' => 'other',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
