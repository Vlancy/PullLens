@extends('errors::layout')

@section('title', __('Unauthorized'))
@section('code', '401')
@section('headline', __('You need to be signed in'))
@section('explanation', __('This page belongs to the signed-in application. Sign in and open the address again.'))

@section('actions')
    @if (Route::has('login'))
        <a class="button button--primary" href="{{ route('login') }}">{{ __('Sign in') }}</a>
    @endif
    <a class="button" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
@endsection
