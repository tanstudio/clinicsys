<!doctype html>
<html lang="zh-HK">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', '職員工作台') · 中醫門診</title><link rel="stylesheet" href="/staff.css">@stack('styles')</head>
<body>
<header class="portal-top"><div class="portal-frame portal-bar">
<a class="portal-brand" href="/staff"><svg viewBox="0 0 32 32" aria-hidden="true"><path fill="currentColor" d="M0 10h10V0h12v10h10v12H22v10H10V22H0z"/></svg>中醫門診</a>
@if(\App\Support\StaffAccess::account())
<div class="portal-tools">
@if(\App\Support\StaffAccess::hq())
@php($branchList = \Illuminate\Support\Facades\DB::table('branches')->orderBy('id')->get())
@if($branchList->isNotEmpty())
<form method="post" action="/staff/branch">@csrf<label>目前分店 <select name="branch_id">@foreach($branchList as $b)<option value="{{ $b->id }}" @selected($b->id == \App\Support\StaffAccess::branch())>{{ $b->name_zh }}</option>@endforeach</select></label><button>切換</button></form>
@else<span>尚未設立分店</span>@endif
@else
<span>{{ \Illuminate\Support\Facades\DB::table('branches')->where('id',\App\Support\StaffAccess::branch())->value('name_zh') }}</span>
@endif
<span>{{ \App\Support\StaffAccess::account()->name }}</span><form method="post" action="/staff/logout">@csrf<button>登出</button></form></div>
@else<a href="/">患者預約</a>@endif
</div></header>
<main class="portal-frame portal-main">
@if(\App\Support\StaffAccess::account() && !request()->is('staff'))<nav class="portal-crumb"><a href="/staff">職員工作台</a></nav>@endif
@if(session('status'))<p class="portal-status" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul class="portal-status" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@if(request()->is('staff', 'staff/schedule'))
@yield('content')
@else
<div class="module-page">
@yield('content')
</div>
@endif
</main>@stack('scripts')</body></html>
