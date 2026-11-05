@extends('staff.layout')
@section('title', '營運報表')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'reports'])</span><h1>營運報表</h1></header>
<form method="get" action="{{ url('/staff/reports') }}">
<label>由 <input type="date" name="from" value="{{ $from->toDateString() }}" required></label>
<label>至 <input type="date" name="to" value="{{ $to->toDateString() }}" required></label>
@if(App\Support\StaffAccess::hq())<label>分店 <select name="branch"><option value="">全部分店</option>@foreach($branches as $id=>$name)<option value="{{ $id }}" @selected($branch == $id)>{{ $name }}</option>@endforeach</select></label>@endif
<label>圖表指標 <select name="metric">@foreach($metrics as $key=>[$name, $unit])<option value="{{ $key }}" @selected($metric === $key)>{{ $name }}</option>@endforeach</select></label>
<button>更新報表</button>
<button name="format" value="csv">匯出分店指標 CSV</button>
</form>
<p>可將篩選後的報表加入瀏覽器書籤。CSV 包含所選日期及分店的六項指標；無分母的比率留空。</p>
<div class="revenue-total"><span>實收總額</span><strong>HK$ {{ number_format(array_sum(array_column($summary, 'revenue')), 2) }}</strong></div>
<h2>{{ $metrics[$metric][0] }}（{{ $metrics[$metric][1] }}）</h2>
@php($max = max([1, ...array_values(array_filter(array_column($summary, $metric), 'is_numeric'))]))
@foreach($summary as $id=>$row)
<p>{{ $branches[$id] }}　@if($row[$metric] !== null)<meter min="0" max="{{ $max }}" value="{{ $row[$metric] }}" aria-label="{{ $branches[$id] }} {{ $metrics[$metric][0] }}"></meter> {{ number_format($row[$metric], $metric === 'usage' ? 3 : 2) }}@else — @endif</p>
@endforeach
<div class="sheet-scroll"><table><thead><tr><th>分店</th>@foreach($metrics as [$name,$unit])<th>{{ $name }}（{{ $unit }}）</th>@endforeach</tr></thead><tbody>
@foreach($summary as $id=>$row)<tr><th>{{ $branches[$id] }}</th>@foreach($metrics as $key=>[$name,$unit])<td>{{ $row[$key] === null ? '—' : number_format($row[$key], $key === 'usage' ? 3 : 2) }}</td>@endforeach</tr>@endforeach
</tbody></table></div>
<h2>醫師效率</h2>
<div class="sheet-scroll"><table><thead><tr><th>分店</th><th>醫師</th><th>已記錄診次</th><th>排班時數</th><th>診次／小時</th></tr></thead><tbody>
@forelse($doctors as $d)<tr><td>{{ $branches[$d->branch_id] }}</td><td>{{ $d->name_zh }}</td><td>{{ $d->completed }}</td><td>{{ number_format($d->capacity * 0.5, 1) }}</td><td>{{ number_format($d->completed / ($d->capacity * 0.5), 2) }}</td></tr>@empty<tr><td colspan="5">此期間沒有排班。</td></tr>@endforelse
</tbody></table></div>
<h2>藥材配發明細</h2>
<div class="sheet-scroll"><table><thead><tr><th>分店</th><th>藥材</th><th>用量（克）</th></tr></thead><tbody>
@forelse($usage as $u)<tr><td>{{ $branches[$u->branch_id] }}</td><td>{{ $u->herb }}</td><td>{{ number_format($u->mg / 1000, 3) }}</td></tr>@empty<tr><td colspan="3">此期間沒有配發紀錄。</td></tr>@endforelse
</tbody></table></div>
<details><summary>指標計算方法</summary>
<p>日期以香港時間計。收入按收據簽發日統計，藥材用量按配發日統計。</p>
<p>按預約時段日期計算預約率：未取消預約數／開放時段數。</p>
<p>覆診率：上述時段內有同分店較早診症紀錄的患者人數／期內就診患者人數。每名患者只計一次。較早紀錄包括期內先前診症及報表期前診症。</p>
<p>庫存使用率：期內配發克數／（期初庫存克數＋期內入庫克數）。各藥材按重量合計。</p>
<p>醫師效率：上述時段的已記錄診次／排班時數。每個開放時段為半小時。分母為零時顯示「—」。</p>
</details>
@endsection
