@extends('auth.layout')

@section('title', 'Reset password')

@section('content')
    <h1>Choose a new password</h1>
    <p>After saving, you will be signed out on every device. Sign in again in the ARC Ride app.</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <label for="email">Email address</label>
        <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username">
        @error('email')
            <div class="error">{{ $message }}</div>
        @enderror

        <label for="password">New password</label>
        <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
        @error('password')
            <div class="error">{{ $message }}</div>
        @enderror

        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">

        <button type="submit">Save new password</button>
    </form>
@endsection
