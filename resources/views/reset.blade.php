@extends('superadmin::layout')

@section('content')
    <h1>{{ __('Set a new super admin password') }}</h1>
    <p>{{ __('Choose a new password for the protected super admin account. The link you used is single-use.') }}</p>

    <form method="POST" action="{{ route('superadmin.recovery.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <label for="password">{{ __('New password (min. 12 characters)') }}</label>
        <input id="password" type="password" name="password" required autofocus autocomplete="new-password">

        <label for="password_confirmation">{{ __('Confirm password') }}</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

        <button type="submit">{{ __('Update password') }}</button>
    </form>
@endsection
