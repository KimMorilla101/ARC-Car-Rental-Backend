<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutAndTokenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sanctum caches the resolved user per guard; reset it so each request authenticates afresh,
     * like separate HTTP requests from the phone would.
     */
    private function freshRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    public function test_me_returns_the_wrapped_user_resource(): void
    {
        $user = User::factory()->create(['address' => 'Davao City', 'trust_score' => 92]);
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'renter',
                    'phone' => $user->phone,
                    'address' => 'Davao City',
                    'avatar_url' => null,
                    'trust_score' => 92,
                ],
            ]);
    }

    public function test_requests_without_a_token_get_401_json(): void
    {
        $this->getJson('/api/user')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_api_returns_401_even_without_an_accept_json_header(): void
    {
        $this->get('/api/user')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('1|not-a-real-token')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $phoneToken = $user->createToken('phone')->plainTextToken;
        $tabletToken = $user->createToken('tablet')->plainTextToken;

        $this->withToken($phoneToken)->postJson('/api/auth/logout')->assertNoContent();

        $this->freshRequest()->withToken($phoneToken)->getJson('/api/user')->assertUnauthorized();
        $this->freshRequest()->withToken($tabletToken)->getJson('/api/user')->assertOk();
        $this->assertSame(['tablet'], $user->tokens()->pluck('name')->all());
    }

    public function test_tokens_expire_after_thirty_days(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phone',
        ])->json('token');

        $this->travel(29)->days();
        $this->freshRequest()->withToken($token)->getJson('/api/user')->assertOk();

        $this->travel(2)->days();
        $this->freshRequest()->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }
}
