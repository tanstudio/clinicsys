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
