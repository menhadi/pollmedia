<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>India map · Pollmedia</title>
@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">
@include('india-map-assets')
</head><body class="election-ui">@include('public-header')
@php $issueService=app(\App\Services\CommunityAreaIssues::class); $issueArea=request('village') ? $issueService->context('village','Selected village','',(string)request('village')) : (request('district') ? $issueService->context('district','Selected district',(string)request('state'),(string)request('district')) : (request('kind') && request('code') ? $issueService->context((string)request('kind'),(string)request('name','Selected constituency'),(string)request('state'),(string)request('code')) : (request('state') ? $issueService->context('state','Selected state',(string)request('state')) : $issueService->context('india','India')))); @endphp
@include('community-area-issues')
<main class="india-explorer">@include('india-map-explorer')</main>@include('public-footer')</body></html>
