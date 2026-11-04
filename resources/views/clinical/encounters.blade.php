@extends('staff.layout')
@section('title', '診療病歷')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'encounters'])</span><h1>診療病歷</h1></header>
<form method="post" action="/staff/encounters">@csrf
    <input type="hidden" name="patient" value="{{ $patientId }}">
    <label>症狀、診斷、處方或治療計劃 <input name="q" maxlength="150" value="{{ $term }}"></label>
    <button>搜尋病歷</button>
</form>
<div class="sheet-scroll"><table><thead><tr><th>病歷編號</th><th>患者</th><th>記錄日期</th><th>診斷</th></tr></thead>
<tbody>@forelse($matches as $entry)
<tr><td><a href="/staff/encounters/{{ $entry->id }}">#{{ $entry->id }}</a></td>
<td><a href="/staff/patients/{{ $entry->patient_id }}">{{ $names[$entry->patient_id]->name }}</a></td>
<td>{{ $entry->created_at->timezone('Asia/Hong_Kong')->format('Y-m-d H:i') }}</td>
<td>{{ \Illuminate\Support\Str::limit($entry->clinical_record['diagnosis'], 80) }}</td></tr>
@empty<tr><td colspan="4">找不到符合條件的病歷。</td></tr>@endforelse</tbody></table></div>
<details><summary>新增分店病歷範本</summary>
<p>請填寫可重用的提示，勿輸入患者個人資料。</p>
<form method="post" action="/staff/encounters/templates">@csrf
    <label>範本名稱 <input name="name" required maxlength="80"></label>
    @include('clinical.fields', ['record' => [], 'requireDiagnosis' => false])
    <button>儲存範本</button>
</form></details>
@endsection
