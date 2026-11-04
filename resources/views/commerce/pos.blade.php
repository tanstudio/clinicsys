@extends('staff.layout')
@section('title', '收銀')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'pos'])</span><h1>收銀</h1></header>
<p>選擇本分店已登記的診症，確認收款後簽發港元收據。</p>
@if($encounters->isEmpty())
<p>目前沒有待收款的診症。</p>
@else
<form method="post" action="{{ url('/staff/pos') }}">
@csrf
<label>診症及預約 <select name="encounter_id" required>@foreach($encounters as $e)<option value="{{ $e->id }}" @selected(old('encounter_id') == $e->id)>#{{ $e->id }} · {{ $e->reference }}</option>@endforeach</select></label>
<label>收款項目 <input name="description" maxlength="160" value="{{ old('description') }}" required></label>
<label>實收金額（HK$） <input type="number" name="amount" step="0.01" min="0.01" max="9999999.99" value="{{ old('amount') }}" required></label>
<label>付款方式 <select name="method">@foreach(['cash'=>'現金','card'=>'銀行卡','fps'=>'轉數快','other'=>'其他'] as $key=>$name)<option value="{{ $key }}" @selected(old('method') === $key)>{{ $name }}</option>@endforeach</select></label>
<button>確認收款及簽發收據</button>
</form>
@endif
<h2>已簽發收據</h2>
<div class="sheet-scroll"><table><thead><tr><th>收據號碼</th><th>項目</th><th>實收港元</th></tr></thead><tbody>
@forelse($receipts as $r)<tr><td><a href="{{ url('/staff/pos/'.$r->id) }}">#{{ $r->id }}</a></td><td>{{ $r->description }}</td><td>{{ number_format($r->amount_cents / 100, 2) }}</td></tr>
@empty<tr><td colspan="3">尚無收據。</td></tr>@endforelse
</tbody></table></div>
@if($receipts->previousPageUrl())<a href="{{ $receipts->previousPageUrl() }}">上一頁</a>@endif
@if($receipts->nextPageUrl())<a href="{{ $receipts->nextPageUrl() }}">下一頁</a>@endif
@endsection
