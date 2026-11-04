@extends('staff.layout')
@section('content')
@php($today = today('Asia/Hong_Kong'))
<header class="home-heading">
    <div class="home-date"><span>{{ $today->format('Y / m') }}</span><b>{{ $today->format('d') }}</b></div>
    <div><h1>職員工作台</h1>
    <p>{{ $today->locale('zh_TW')->translatedFormat('l') }} · 香港時間</p></div>
</header>
<h2 class="home-section">工作項目</h2>
<div class="portal-index">
@if(\App\Support\StaffAccess::hq())
<a class="portal-item" href="/staff/branches"><span class="item-icon">@include('staff.icon', ['module'=>'branches'])</span><span class="item-text"><strong>分店管理</strong><small>查看分店名單及新增分店</small></span></a>
@endif
@if(\App\Support\StaffAccess::currentBranch() !== null)
@foreach([
 ['門診排班','排班及可預約時段','schedule',['reception']],
 ['患者檔案','聯絡資料、預約紀錄及跟進','patients',['doctor','reception']],
 ['診症紀錄','症狀、診斷、處方及治療計劃','encounters',['doctor']],
 ['中藥庫存','入庫、配藥及分店存量','inventory',['doctor','reception']],
 ['收費及收據','門診收費與收據紀錄','pos',['reception']],
 ['營運報表','預約、收入及分店營運指標','reports',['admin']],
 ['WhatsApp 收件匣','患者訊息、客服分流及跟進','inbox',['reception']],
 ['人事管理','員工檔案、考勤及請假','hr',['doctor','reception','hr']],
] as [$name,$description,$path,$roles])
@if(\App\Support\StaffAccess::can(...$roles))
<a class="portal-item" href="/staff/{{ $path }}"><span class="item-icon">@include('staff.icon', ['module'=>$path])</span><span class="item-text"><strong>{{ $name }}</strong><small>{{ $description }}</small></span><svg class="item-arrow" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true"><path d="m8 5 5 5-5 5"/></svg></a>
@endif
@endforeach
@endif
</div>@endsection
