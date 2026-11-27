@extends('staff.layout')
@section('title', '患者檔案')
@section('content')
<a href="/staff/patients">返回患者名冊</a>
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'patients'])</span><h1>{{ $person->name }}</h1></header>
<p>聯絡電話 {{ $person->phone }}</p>
@if($email)<p>本店最近的預約電郵 {{ $email }}</p>@endif
<h2>分店標籤及客群</h2>
<form method="post" action="/staff/patients/{{ $person->id }}/profile">@csrf
    <label>標籤（以逗號分隔） <input name="tags" value="{{ $profile['tags'] }}" maxlength="300"></label>
    <label>客群 <input name="segment" value="{{ $profile['segment'] }}" maxlength="80"></label>
    <button>儲存分類</button>
</form>
<h2>會員等級及療程套票</h2>
<p>會員等級：<strong>{{ $loyalty['tier']->name ?? '未達任何等級' }}</strong>@if($loyalty['tier'] && $loyalty['tier']->discount_percent) · 診金折扣 {{ $loyalty['tier']->discount_percent }}%@endif
 · 近 12 個月實收 HK$ {{ number_format($loyalty['spend_cents'] / 100, 2) }}
 · {{ $loyalty['manual'] ? '總部手動指定（'.$loyalty['reason'].'）' : '按實收自動計算' }}</p>
@if(\App\Support\StaffAccess::hq())
<form method="post" action="/staff/patients/{{ $person->id }}/tier">@csrf
    <label>手動指定等級 <select name="tier_id"><option value="">按實收自動計算</option>@foreach($tiers as $tier)<option value="{{ $tier->id }}" @selected($loyalty['manual'] && $loyalty['tier']->id == $tier->id)>{{ $tier->name }}</option>@endforeach</select></label>
    <label>原因 <input name="reason" maxlength="200" value="{{ $loyalty['manual'] ? $loyalty['reason'] : '' }}"></label>
    <button>更新等級</button>
</form>
@endif
<div class="sheet-scroll"><table><thead><tr><th>套票</th><th>服務</th><th>剩餘／總次數</th><th>有效期</th><th>狀態</th></tr></thead><tbody>
@forelse($packages as $p)<tr><td>{{ $p->name }}</td><td>{{ $p->service }}</td><td>{{ $balances[$p->id] }}／{{ $p->total_sessions }}</td>
<td>{{ $p->starts_on }} 至 {{ $p->expires_on }}</td>
<td>{{ $p->expires_on < $today ? '已過期' : ($balances[$p->id] > 0 ? '可使用' : '已用完') }}</td></tr>
@empty<tr><td colspan="5">暫無療程套票。</td></tr>@endforelse
</tbody></table></div>
@if($ledger->isNotEmpty())
<details><summary>套票使用紀錄</summary>
<div class="sheet-scroll"><table><thead><tr><th>時間（香港）</th><th>套票</th><th>操作</th><th>次數</th><th>分店</th><th>診症</th><th>備註</th></tr></thead><tbody>
@foreach($ledger as $l)<tr><td>{{ \Illuminate\Support\Carbon::parse($l->created_at, 'UTC')->tz('Asia/Hong_Kong')->format('Y-m-d H:i') }}</td>
<td>{{ $packages->firstWhere('id', $l->package_id)->name }}</td>
<td>{{ ['purchase'=>'購買','redeem'=>'扣減','void'=>'撤銷扣減'][$l->kind] ?? $l->kind }}</td><td>{{ $l->sessions > 0 ? '+'.$l->sessions : $l->sessions }}</td>
<td>{{ $l->branch }}</td><td>{{ $l->encounter_id ? '#'.$l->encounter_id : '—' }}</td>
<td>{{ $l->reason ?? '' }}@if($l->kind === 'redeem' && in_array($l->id, $voided)) 已撤銷
@elseif($l->kind === 'redeem' && \App\Support\StaffAccess::can('admin'))
<form method="post" action="/staff/packages/ledger/{{ $l->id }}/void">@csrf<label>撤銷原因 <input name="reason" maxlength="200" required></label><button>撤銷扣減</button></form>@endif</td></tr>@endforeach
</tbody></table></div></details>
@endif
@if(\App\Support\StaffAccess::can('reception'))
@if($plans->isNotEmpty())
<form method="post" action="/staff/patients/{{ $person->id }}/packages">@csrf
    <label>出售套票 <select name="plan_id">@foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }} · {{ $plan->service }} · {{ $plan->sessions + $plan->bonus_sessions }} 次 · HK$ {{ number_format($plan->price_cents / 100, 2) }} · {{ $plan->validity_days }} 日</option>@endforeach</select></label>
    <label>付款方式 <select name="method">@foreach(['cash'=>'現金','card'=>'銀行卡','fps'=>'轉數快','other'=>'其他'] as $key=>$name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
    <button>確認收款及售出</button>
</form>
@else<p>本分店未有啟用的套票方案。</p>@endif
@endif
<h2>預約及到診紀錄</h2>
@if(\App\Support\StaffAccess::can('doctor'))<p><a href="/staff/encounters?patient={{ $person->id }}">搜尋此患者病歷</a></p>@endif
<div class="sheet-scroll"><table><thead><tr><th>預約時間</th><th>服務及醫師</th><th>狀態</th><th>預約編號</th></tr></thead>
<tbody>@foreach($bookings as $b)
<tr><td>{{ $b->starts_at }}</td><td>{{ $b->service }} · {{ $b->practitioner }}</td>
<td>@if($b->cancelled_at) 已取消 @elseif($encounters->has($b->id))
<a href="/staff/encounters/{{ $encounters[$b->id] }}">檢視病歷</a>
@elseif(\App\Support\StaffAccess::can('doctor'))<a href="/staff/encounters/new/{{ $b->id }}">填寫病歷</a>@else 已預約 @endif</td>
<td>{{ $b->reference }}</td></tr>@endforeach</tbody></table></div>
@if($events->isNotEmpty())
<details><summary>預約變更記錄</summary>
<div class="sheet-scroll"><table><thead><tr><th>預約</th><th>操作</th><th>原時段</th><th>新時段</th></tr></thead>
<tbody>@foreach($events as $event)<tr><td>#{{ $event->appointment_id }}</td>
<td>{{ ['confirmed'=>'建立預約','rescheduled'=>'更改時段','cancelled'=>'取消預約'][$event->event] ?? $event->event }}</td>
<td>{{ $event->old_start ?: '—' }}</td><td>{{ $event->new_start ?: '—' }}</td></tr>@endforeach</tbody></table></div></details>
@endif
<h2>個人跟進</h2>
<form method="post" action="/staff/patients/{{ $person->id }}/followups">@csrf
    <label>跟進日期 <input type="date" name="due_on" required></label>
    <label>跟進事項 <textarea name="note" maxlength="1500" required></textarea></label>
    <button>新增跟進事項</button>
</form>
<ul>@forelse($followups as $item)
<li>{{ $item->due_on }} · {{ \Illuminate\Support\Facades\Crypt::decryptString($item->note) }}
@if($item->completed_at) · 已完成
@else<form method="post" action="/staff/patients/{{ $person->id }}/followups/{{ $item->id }}/complete">@csrf<button>標示完成</button></form>@endif</li>
@empty<li>暫無跟進事項。</li>@endforelse</ul>
<h2>WhatsApp 通訊紀錄</h2>
<ul>@forelse($threads as $thread)
<li><a href="/staff/inbox/{{ $thread->id }}">#{{ $thread->id }} · {{ $thread->category }}</a>
 · {{ $thread->status }} · {{ $thread->updated_at }}</li>
@empty<li>暫無已連結的對話。</li>@endforelse</ul>
@endsection
