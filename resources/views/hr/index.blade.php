@extends('staff.layout')
@section('title', '人事管理')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'hr'])</span><h1>人事管理</h1></header>
<p>選擇員工以查看人事檔案、更表、考勤和假期。</p>
<div class="sheet-scroll"><table>
    <thead><tr><th>員工</th><th>電郵</th><th>職能</th></tr></thead>
    <tbody>
    @forelse($people as $person)
        <tr><td><a href="{{ url('/staff/hr/people/'.$person->id) }}">{{ $person->name }}</a></td><td>{{ $person->email }}</td><td>{{ $person->role }}</td></tr>
    @empty
        <tr><td colspan="3">目前沒有員工。</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
