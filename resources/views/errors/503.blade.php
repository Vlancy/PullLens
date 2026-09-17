@extends('errors::layout')

@section('title', __('Unavailable'))
@section('code', '503')
@section('headline', __('PullLens is down for maintenance'))
@section('explanation', __('This instance is being updated. Nothing has been lost - reviews queued before the maintenance window resume when it finishes. Reload the page in a few minutes.'))

{{--
    The default actions point at the home page and the guide, and during a
    maintenance window both of those answer 503 as well. Offering them here would
    walk the visitor in a circle, so the only thing on offer is the page they
    already wanted.
--}}
@section('actions')
    <a class="button button--primary" href="{{ url()->current() }}">{{ __('Try again') }}</a>
@endsection
