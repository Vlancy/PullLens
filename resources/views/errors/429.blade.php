@extends('errors::layout')

@section('title', __('Too many requests'))
@section('code', '429')
@section('headline', __('Too many requests, too quickly'))
@section('explanation', __('This instance rate limits some actions - triggering reviews, signing in - so that one client cannot exhaust it for everybody. Wait a minute and try again.'))
