<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Create a renter account and sign it in on the requesting device.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'phone', 'password']));

        event(new Registered($user));

        return response()->json([
            'token' => $this->issueToken($user, $request->validated('device_name')),
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Exchange valid credentials for a bearer token.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return response()->json([
            'token' => $this->issueToken($user, $request->validated('device_name')),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Revoke only the token used for this request, so other devices stay signed in.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * The signed-in renter's account.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Email a password reset link. The response is identical whether or not the account exists,
     * so this endpoint cannot be used to discover registered email addresses.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->safe()->only(['email']));

        return response()->json([
            'message' => 'If an account exists for that email address, we have sent a password reset link.',
        ]);
    }

    /**
     * Issue a Sanctum token that expires after the configured lifetime.
     */
    private function issueToken(User $user, string $deviceName): string
    {
        $lifetimeMinutes = config('sanctum.expiration');

        return $user->createToken(
            $deviceName,
            ['*'],
            $lifetimeMinutes ? now()->addMinutes((int) $lifetimeMinutes) : null,
        )->plainTextToken;
    }
}
