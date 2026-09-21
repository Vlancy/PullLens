@extends('errors::layout')

@section('title', __('Method not allowed'))
@section('code', '405')
@section('headline', __('That address is real, but not for this kind of request'))

{{--
    The most common way to land here is a person opening a machine endpoint in a
    browser - the webhook receiver, which only ever answers POST from the provider.
    Naming the methods the address does accept turns a dead end into an answer, and
    the exception already carries them in its Allow header.
--}}
@php
    $allowed = collect(explode(',', (string) (($exception ?? null)?->getHeaders()['Allow'] ?? '')))
        ->map(fn (string $method): string => trim($method))
        ->filter()
        ->values();
@endphp

@section('explanation', $allowed->isEmpty()
    ? __('This address does not accept the kind of request your browser made. It is most likely an endpoint meant for another machine to call rather than a page to open.')
    : __('This address does not accept :used requests - only :allowed. It is most likely an endpoint meant for another machine to call rather than a page to open.', [
        'used' => request()->method(),
        'allowed' => $allowed->join(', ', ' or '),
    ]))
