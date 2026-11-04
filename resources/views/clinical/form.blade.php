@extends('staff.layout')
@section('title', '填寫病歷')
@section('content')
<a href="/staff/patients/{{ $patient->id }}">返回患者檔案</a>
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'encounters'])</span><h1>填寫病歷 · {{ $patient->name }}</h1></header>
<p>預約時間 {{ $booking->starts_at }} · {{ $booking->reference }}</p>
@if($templates->isNotEmpty())
<form method="get">
<label>病歷範本 <select name="template"><option value="">空白病歷</option>
@foreach($templates as $template)<option value="{{ $template->id }}" @selected($chosen === $template->id)>{{ $template->name }}</option>@endforeach
</select></label><button>載入範本</button>
</form>@endif
<form method="post" action="/staff/encounters/new/{{ $booking->id }}">@csrf
@include('clinical.fields', ['requireDiagnosis' => true])
<button>確認並儲存病歷</button></form>
@endsection
