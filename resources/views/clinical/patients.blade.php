@extends('staff.layout')
@section('title', '患者名冊')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'patients'])</span><h1>{{ $allBranches ? '全線患者名冊' : '患者名冊' }}</h1></header>
@if(\App\Support\StaffAccess::hq())
<p><a href="/staff/patients">目前分店</a> · <a href="/staff/patients?scope=all">全線分店</a></p>
@endif
<form method="post" action="/staff/patients">
    @csrf
    <input type="hidden" name="scope" value="{{ $allBranches ? 'all' : 'branch' }}">
    <label>姓名、電話或標籤 <input name="q" value="{{ $term }}" maxlength="150"></label>
    <label>客群 <input name="segment" value="{{ $segment }}" maxlength="80"></label>
    <button>篩選患者</button> <a href="/staff/patients">清除</a>
</form>
<div class="sheet-scroll"><table>
    <thead><tr><th>患者</th><th>分店</th><th>預約次數</th><th>聯絡電話</th><th>標籤</th><th>客群</th></tr></thead>
    <tbody>@forelse($patients as $row)
    <tr><td>@if($allBranches)
        <form method="post" action="/staff/patients/{{ $row['patient']->id }}/open-branch">@csrf
        <input type="hidden" name="branch_id" value="{{ $row['visit']->branch_id }}">
        <button>{{ $row['patient']->name }}</button></form>
        @else<a href="/staff/patients/{{ $row['patient']->id }}">{{ $row['patient']->name }}</a>@endif</td>
        <td>{{ $row['visit']->branch_name }}</td><td>{{ $row['visit']->booking_count }}</td>
        <td>{{ $row['patient']->phone }}</td><td>{{ $row['tags'] ?: '—' }}</td><td>{{ $row['segment'] ?: '—' }}</td></tr>
    @empty<tr><td colspan="6">找不到符合條件的患者。</td></tr>@endforelse</tbody>
</table></div>
@endsection
