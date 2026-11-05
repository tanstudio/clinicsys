@extends('staff.layout')
@section('title', '藥材庫存')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'inventory'])</span><h1>藥材庫存</h1></header>
<p>記錄本分店藥材的收貨及配發，數量以克計。</p>
<h2>記錄庫存變動</h2>
<form action="{{ url('/staff/inventory') }}" method="post">
@csrf
<input type="hidden" name="reference" value="{{ old('reference', (string) Illuminate\Support\Str::uuid()) }}">
<label>庫存操作 <select name="kind"><option value="receive">入庫</option><option value="dispense" @selected(old('kind') === 'dispense')>配發</option></select></label>
<label>藥材名稱 <input name="herb" list="herbs" maxlength="100" value="{{ old('herb') }}" required></label>
<datalist id="herbs">@foreach($stocks as $stock)<option value="{{ $stock->herb }}">@endforeach</datalist>
<label>數量（克） <input type="number" name="grams" min="0.001" max="9999999.999" step="0.001" value="{{ old('grams') }}" required></label>
<label>配發診症 <select name="encounter_id"><option value="">不連結診症</option>@foreach($encounters as $e)<option value="{{ $e->id }}" @selected(old('encounter_id') == $e->id)>{{ $patients[$e->patient_id]->name }} · {{ $e->starts_at }} · #{{ $e->id }}</option>@endforeach</select></label>
<label>備註 <input name="note" maxlength="160" value="{{ old('note') }}"></label><button>記錄變動</button>
</form>
<h2>現有存量</h2>
<div class="sheet-scroll"><table><thead><tr><th>藥材</th><th>結餘（克）</th></tr></thead><tbody>
@forelse($stocks as $stock)<tr><td>{{ $stock->herb }}</td><td>{{ number_format($stock->milligrams / 1000, 3) }}</td></tr>@empty<tr><td colspan="2">尚無庫存，請先記錄入庫。</td></tr>@endforelse
</tbody></table></div>
<h2>庫存流水</h2>
<form method="get" action="/staff/inventory" aria-label="庫存流水篩選">
<label>藥材（完整名稱）<input name="herb_filter" list="herbs" maxlength="100" value="{{ $filters['herb_filter'] ?? '' }}"></label>
<label>流水類別 <select name="movement_kind"><option value="">全部操作</option><option value="receive" @selected(($filters['movement_kind'] ?? '') === 'receive')>入庫</option><option value="dispense" @selected(($filters['movement_kind'] ?? '') === 'dispense')>配發</option></select></label>
<label>流水日期由 <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
<label>流水日期至 <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
<button>篩選流水</button><a href="/staff/inventory">清除篩選</a>
</form>
<p>符合條件 {{ $movements->total() }} 筆流水（含全部分頁）。篩選不影響上方現有存量。</p>
<div class="sheet-scroll"><table><thead><tr><th>香港時間</th><th>藥材</th><th>操作</th><th>克數</th><th>診症</th><th>備註</th></tr></thead><tbody>
@forelse($movements as $m)<tr><td>{{ Carbon\Carbon::parse($m->created_at, 'UTC')->tz('Asia/Hong_Kong')->format('Y-m-d H:i') }}</td><td>{{ $m->herb }}</td><td>{{ $m->kind === 'receive' ? '入庫' : '配發' }}</td><td>{{ number_format($m->milligrams / 1000, 3) }}</td><td>{{ $m->encounter_id ?? '—' }}</td><td>{{ $m->note }}</td></tr>
@empty<tr><td colspan="6">尚無流水。</td></tr>@endforelse
</tbody></table></div>
@if($movements->previousPageUrl())<a href="{{ $movements->previousPageUrl() }}">上一頁</a>@endif
@if($movements->nextPageUrl())<a href="{{ $movements->nextPageUrl() }}">下一頁</a>@endif
@endsection
