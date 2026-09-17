@extends('errors::layout')

@section('title', __('Payment required'))
@section('code', '402')
@section('headline', __('This action needs a payment step first'))
@section('explanation', __('Something in front of this instance is asking for a payment that has not been completed. PullLens itself is source-available and charges nothing, so this comes from the deployment rather than from the application.'))
