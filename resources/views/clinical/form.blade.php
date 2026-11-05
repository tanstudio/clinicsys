@extends('staff.layout')
@section('title', '填寫病歷')
@section('content')
<a href="/staff/patients/{{ $patient->id }}">返回患者檔案</a>
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'encounters'])</span><h1>填寫病歷 · {{ $patient->name }}</h1></header>
<p>預約時間 {{ $booking->starts_at }} · {{ $booking->reference }}</p>
@if(!empty($recordErrors))
<div class="portal-status" role="alert" tabindex="-1" id="record-errors">
<strong>病歷尚未儲存，請修正以下欄位。</strong>
<p>本次填寫內容仍保留在此頁，修正後可再次儲存。</p>
<ul>@foreach($recordErrors as $field => $message)<li><a href="#record-{{ $field }}">{{ \App\Http\Controllers\ClinicalController::FIELDS[$field] }}：{{ $message }}</a></li>@endforeach</ul>
</div>
@push('scripts')<script>document.getElementById('record-errors').focus();</script>@endpush
@endif
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
