@extends('superadmin::layout')

@section('content')
    <h1>{{ __('Super admin recovery') }}</h1>
    <p>{{ __('Email a password reset link to the protected super admin account\'s own mailbox. Nothing is shown or changed on this page.') }}</p>

    <form method="POST" action="{{ route('superadmin.recovery.send') }}">
        @csrf
        <button type="submit">{{ __('Email me a reset link') }}</button>
    </form>
@endsection
