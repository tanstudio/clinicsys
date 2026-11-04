@extends('staff.layout')
@section('title', '收據')
@push('styles')
<style>@media print{.portal-top,.portal-crumb,.receipt-actions{display:none}.portal-main{width:100%;overflow:visible}}</style>
@endpush
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'pos'])</span><h1>收據 #{{ $item->id }}</h1></header>
<p>{{ $item->branch }} · {{ Carbon\Carbon::parse($item->created_at, 'UTC')->tz('Asia/Hong_Kong')->format('Y-m-d H:i') }}（香港時間）</p>
<dl><dt>收據編號</dt><dd>{{ $item->reference }}</dd><dt>預約編號</dt><dd>{{ $item->booking }}</dd>
<dt>診症編號</dt><dd>#{{ $item->encounter_id }}</dd><dt>收款項目</dt><dd>{{ $item->description }}</dd>
<dt>付款方式</dt><dd>{{ ['cash'=>'現金','card'=>'銀行卡','fps'=>'轉數快','other'=>'其他'][$item->method] }}</dd>
<dt>實收金額</dt><dd><strong>HK$ {{ number_format($item->amount_cents / 100, 2) }}</strong></dd></dl>
<div class="receipt-actions"><button type="button" onclick="window.print()">列印收據</button> <a href="{{ url('/staff/pos') }}">返回收銀</a></div>
@endsection
