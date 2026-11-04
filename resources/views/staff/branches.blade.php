@extends('staff.layout')
@section('title', '分店管理')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'branches'])</span><h1>分店管理</h1></header>
<h2>新增分店</h2>
<form method="post" action="/staff/branches">@csrf
    <label>分店中文名稱<input name="name_zh" maxlength="255" value="{{ old('name_zh') }}" required></label>
    <label>分店英文名稱<input name="name_en" maxlength="255" value="{{ old('name_en') }}" required></label>
    <button>新增分店</button>
</form>
<h2>分店名單</h2>
<div class="sheet-scroll"><table>
<thead><tr><th>中文名稱</th><th>英文名稱</th></tr></thead>
<tbody>@forelse($branches as $branch)
<tr><td>{{ $branch->name_zh }}</td><td>{{ $branch->name_en }}</td></tr>
@empty<tr><td colspan="2">暫無分店。新增第一間分店後即可安排門診。</td></tr>@endforelse</tbody>
</table></div>
@endsection
