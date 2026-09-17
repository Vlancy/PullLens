@extends('errors::layout')

@section('title', __('Page expired'))
@section('code', '419')
@section('headline', __('That page sat still for too long'))
@section('explanation', __('Your session expired before the form was submitted, so it was rejected rather than acted on. Sign in again and repeat what you were doing - nothing was saved.'))

@section('actions')
    @if (Route::has('login'))
        <a class="button button--primary" href="{{ route('login') }}">{{ __('Sign in again') }}</a>
    @endif
    <a class="button" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
@endsection
