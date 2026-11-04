@extends('staff.layout')
@section('title', $title)
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon',['module'=>$type])</span><h1>{{ $title }}</h1></header>
@if($branch === null)
<p>請先建立分店，再設定這間分店的資料。</p><a href="/staff/branches">前往分店管理</a>
@elseif($type === 'accounts')
@include('staff.directory-accounts')
@else
@php($label = $type === 'practitioners' ? '醫師' : '服務項目')
<h2>新增{{ $label }}</h2>
<form method="post" action="/staff/{{ $type }}">@csrf
<input type="hidden" name="expected_branch" value="{{ $branch }}">
<label>中文名稱<input name="name_zh" required maxlength="255"></label><label>英文名稱<input name="name_en" required maxlength="255"></label>
<button>新增{{ $label }}</button></form>
@if($available->isNotEmpty())
<details><summary>加入現有{{ $label }}</summary><form method="post" action="/staff/{{ $type }}">@csrf
<input type="hidden" name="expected_branch" value="{{ $branch }}"><label>選擇{{ $label }}<select name="existing_id">@foreach($available as $row)<option value="{{ $row->id }}">{{ $row->name_zh }} · {{ $row->name_en }} (#{{ $row->id }})</option>@endforeach</select></label>
<button>加入本分店</button></form></details>
@endif
<h2>本分店{{ $label }}</h2>
@forelse($entries as $row)
<article><strong>{{ $row->name_zh }} · {{ $row->name_en }}</strong><p>{{ $row->active ? '已啟用' : '已停用' }}@if($row->branch_count > 1) · 跨分店共用名稱@endif</p>
@if($row->branch_count == 1 || \App\Support\StaffAccess::hq())
<details><summary>編輯名稱</summary><form method="post" action="/staff/{{ $type }}/{{ $row->id }}">@csrf
<input type="hidden" name="expected_branch" value="{{ $branch }}">
<label>中文名稱<input name="name_zh" value="{{ $row->name_zh }}" required maxlength="255"></label>
<label>英文名稱<input name="name_en" value="{{ $row->name_en }}" required maxlength="255"></label><button>儲存名稱</button></form></details>
@endif
<form method="post" action="/staff/{{ $type }}/{{ $row->id }}">@csrf
<input type="hidden" name="expected_branch" value="{{ $branch }}"><input type="hidden" name="active" value="{{ $row->active ? 0 : 1 }}"><button>{{ $row->active ? '於本分店停用' : '於本分店啟用' }}</button></form></article>
@empty<p>本分店暫無{{ $label }}。</p>@endforelse
@endif
@endsection
