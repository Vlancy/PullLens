@extends('errors::layout')

@section('title', __('Forbidden'))
@section('code', '403')
@section('headline', __('Your account cannot open this page'))
{{--
    The exception message is preferred when there is one: a policy that denies a
    request usually explains itself better than a generic sentence can, and that
    explanation is the difference between a user filing a ticket and not.
--}}
@section('explanation', ($exception ?? null)?->getMessage() ?: __('You are signed in, but this page needs a permission your role does not carry. An administrator on this instance can grant it.'))
