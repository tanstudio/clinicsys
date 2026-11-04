@extends('staff.layout')
@section('title', '分店排班')
@section('content')
@push('styles')
<link rel="stylesheet" href="/staff-schedule.css">
@endpush
@php
    $day = \Carbon\CarbonImmutable::parse($date, 'Asia/Hong_Kong');
    $monday = $day->startOfWeek(1);
    $weekdays = ['一','二','三','四','五','六','日'];
    $bookableIds = $available->pluck('id')->flip();
@endphp
<div class="agenda">
<header class="agenda-head">
    <div class="agenda-date" aria-label="{{ $date }}"><span>{{ $day->format('Y / m') }}</span><b>{{ $day->format('d') }}</b></div>
    <div><h1>分店排班</h1><p>星期{{ $weekdays[$day->isoWeekday()-1] }}<i></i>香港時間<i></i>每節 30 分鐘</p></div>
</header>
<section class="agenda-calendar" aria-label="排班日期">
    <div class="agenda-toolbar"><h2>{{ $day->format('Y 年 n 月') }}</h2>
    <form method="get"><input type="date" aria-label="排班日期" name="date" value="{{ $date }}" required><button>查看排班</button></form></div>
    <nav class="agenda-week" aria-label="選擇日期">
    @foreach($weekdays as $index => $weekday)
        @php
            $item = $monday->addDays($index);
        @endphp
        <a href="?date={{ $item->toDateString() }}" @if($item->toDateString() === $date) aria-current="date" @endif aria-label="{{ $item->toDateString() }} 星期{{ $weekday }}"><small>週{{ $weekday }}</small><b>{{ $item->day }}</b></a>
    @endforeach
    </nav>
</section>
<section class="agenda-list">
<div class="agenda-list-head"><div><h2>當日時段</h2><p>共 {{ $slots->count() }} 節 <i></i> 可預約 {{ $slots->filter(fn($slot) => $bookableIds->has($slot->id))->count() }} 節 <i></i> 已預約 {{ $slots->whereNotNull('reference')->count() }} 節</p></div>
<details class="agenda-add"><summary>新增可預約時段</summary><form method="post" action="/staff/schedule/slots">@csrf
<label>日期 <input type="date" name="date" value="{{ $date }}" required></label><label>開始時間 <input type="time" name="time" step="1800" required></label>
<label>醫師 <select name="practitioner_id">@foreach($practitioners as $p)<option value="{{ $p->id }}">{{ $p->name_zh }}</option>@endforeach</select></label>
<label>服務 <select name="service_id">@foreach($services as $s)<option value="{{ $s->id }}">{{ $s->name_zh }}</option>@endforeach</select></label>
<button>開放時段</button></form></details>
</div>
<div class="agenda-columns" aria-hidden="true"><span>開始時間</span><span>當值醫師</span><span>服務項目</span><span>時段狀態</span></div>
@forelse ($slots as $s)
@php
    $future = $s->starts_at > $now->toDateTimeString();
    $status = match(true) {
        (bool)$s->reference => '已預約',
        (bool)$s->closed_at => '已關閉',
        !$future => '已過時',
        $bookableIds->has($s->id) => '可預約',
        default => '未到預約期',
    };
@endphp
<article class="agenda-slot">
<div class="agenda-row">
<time datetime="{{ str_replace(' ','T',$s->starts_at) }}">{{ substr($s->starts_at,11,5) }}</time>
<strong>{{ $s->practitioner }}</strong><span>{{ $s->service }}</span>
<span class="agenda-status @if($status === '可預約') is-open @elseif($s->reference) is-booked @endif">{{ $status }}</span>
</div>
@if($s->reference)<small>預約編號 {{ $s->reference }}</small>@endif
<div class="agenda-actions">
@if($s->patient_id)<a href="/staff/patients/{{ $s->patient_id }}">查看患者檔案</a>@endif
@if(!$s->encounter_id && $s->starts_at > $now->toDateTimeString())
@if($s->reference)
<details><summary>管理預約</summary><form method="post" action="/staff/schedule/{{ $s->reference }}/move">@csrf
<input type="hidden" name="expected_slot" value="{{ $s->id }}"><label>新時段 <select name="slot_id" required><option value="">選擇時段</option>
@foreach ($available as $destination)<option value="{{ $destination->id }}">{{ substr($destination->starts_at,0,16) }} · {{ $destination->practitioner }} · {{ $destination->service }}</option>@endforeach</select></label><button>確認改期</button></form>
<form method="post" action="/staff/schedule/{{ $s->reference }}/cancel">@csrf<input type="hidden" name="expected_slot" value="{{ $s->id }}"><button>確認取消預約</button></form></details>
@else
<form method="post" action="/staff/schedule/slots/{{ $s->id }}">@csrf<input type="hidden" name="state" value="{{ $s->closed_at ? 'open' : 'closed' }}"><button>{{ $s->closed_at ? '重新開放時段' : '關閉時段' }}</button></form>
@if(!$s->closed_at && $s->starts_at < $now->copy()->startOfDay()->addDays(14)->toDateTimeString())
<details><summary>代患者預約</summary><form method="post" action="/staff/schedule/book">@csrf<input type="hidden" name="slot_id" value="{{ $s->id }}">
<label>患者姓名 <input name="name" maxlength="100" required></label><label>香港聯絡電話 <input name="phone" type="tel" pattern="[2-9][0-9]{7}" required></label>
<label>電郵（選填） <input type="email" name="email" maxlength="254"></label><label><input type="checkbox" value="1" name="whatsapp_opt_in"> 患者已同意接收此預約的 WhatsApp 確認、更改、提醒、付款通知及診後意見邀請。</label><button>確認預約</button></form></details>
@endif
@endif
@endif
</div></article>
@empty<p class="agenda-empty">當日尚未安排時段。</p>@endforelse
</section></div>
@endsection
