@extends('errors::layout')

@section('title', __('Server error'))
@section('code', '500')
@section('headline', __('Something broke on our side'))
@section('explanation', __('This is not your fault and nothing you did caused it. The failure has been written to the application log; an operator reading it will find the detail there. Try again in a moment.'))
