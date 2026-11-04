@extends('staff.layout')
@section('title', '檢視病歷')
@section('content')
<a href="/staff/patients/{{ $patient->id }}">返回患者檔案</a>
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'encounters'])</span><h1>{{ $patient->name }} · 病歷 #{{ $current->id }}</h1></header>
@if($previous->isNotEmpty())
<form method="get">
<label>選擇同一患者的其他病歷
<select name="compare" required>@foreach($previous as $entry)
    <option value="{{ $entry->id }}" @selected($other?->id === $entry->id)>#{{ $entry->id }} · {{ $entry->created_at->timezone('Asia/Hong_Kong')->format('Y-m-d H:i') }}</option>
@endforeach</select></label><button>並排比較</button></form>@endif
<div class="sheet-scroll"><table><thead><tr><th>項目</th>
@foreach(array_filter([$current,$other]) as $visit)<th>病歷 #{{ $visit->id }}<br>{{ $visit->created_at->timezone('Asia/Hong_Kong')->format('Y-m-d H:i') }}</th>@endforeach
</tr></thead><tbody>
@foreach(\App\Http\Controllers\ClinicalController::FIELDS as $key => $label)
<tr><th>{{ $label }}</th>@foreach(array_filter([$current,$other]) as $visit)
<td style="white-space:pre-wrap">{{ $key === 'symptoms' ? implode('、',$visit->clinical_record[$key]) : ($visit->clinical_record[$key] ?: '—') }}</td>
@endforeach</tr>@endforeach
</tbody></table></div>
@endsection
