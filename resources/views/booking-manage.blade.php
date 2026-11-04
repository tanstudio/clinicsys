<!doctype html><html lang="{{ $en ? 'en' : 'zh-HK' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $en ? 'My appointments' : '我的預約' }}</title><link rel="stylesheet" href="/booking.css"></head><body>
<header><div class="wrap nav"><a href="/?lang={{ $en ? 'en' : 'zh' }}">{{ $en ? 'Book a visit' : '線上預約' }}</a><a href="?lang={{ $en ? 'zh' : 'en' }}">{{ $en ? '繁體中文' : 'English' }}</a></div></header>
<main class="wrap"><div class="hero">@include('patient-calendar-icon')<div><h1>{{ $en ? 'My appointments' : '我的預約' }}</h1><p>{{ $en ? 'Appointments booked in this browser session. All times are in Hong Kong time.' : '顯示此瀏覽器工作階段內建立的預約。時間為香港時間。' }}</p></div></div>
@if(session('booking_status'))
<p class="notice" role="status">{{ session('booking_status') === 'cancelled' ? ($en ? 'Appointment cancelled.' : '預約已取消。') : ($en ? 'The appointment changed or has started. Review its current details.' : '預約資料已更改或已開始。請核對最新資料。') }}</p>
@endif
@forelse ($bookings as $booking)
<article class="management"><div class="visit-head"><div class="date-block"><small>{{ substr($booking->starts_at,0,7) }}</small><b>{{ substr($booking->starts_at,8,2) }}</b></div>
<div><h2>{{ substr($booking->starts_at,11,5) }}</h2><strong>{{ $en ? $booking->branch_en : $booking->branch_zh }}</strong></div></div>
<p>{{ $en ? $booking->service_en : $booking->service_zh }} · {{ $en ? $booking->practitioner_en : $booking->practitioner_zh }}</p>
<p>{{ $en ? 'Booking reference' : '預約編號' }} <span class="management-ref">{{ $booking->reference }}</span></p>
@if ($booking->cancelled_at)<p class="badge">{{ $en ? 'Cancelled' : '已取消' }}</p>
@elseif (!$booking->encounter_id && $booking->starts_at > now('Asia/Hong_Kong')->toDateTimeString())
<div class="management-actions"><a href="/appointments/{{ $booking->reference }}/move?lang={{ $en ? 'en' : 'zh' }}">{{ $en ? 'Reschedule' : '更改時段' }}</a>
<details><summary>{{ $en ? 'Cancel appointment' : '取消預約' }}</summary><form method="post" action="/appointments/{{ $booking->reference }}/cancel">@csrf
<input type="hidden" name="expected_slot" value="{{ $booking->slot_id }}"><input type="hidden" name="lang" value="{{ $en ? 'en' : 'zh' }}"><p>{{ $en ? 'Cancelling will release this time for another patient.' : '取消後，此時段可供其他患者預約。' }}</p><button class="cancel-confirm">{{ $en ? 'Confirm cancellation' : '確認取消' }}</button></form></details></div>
@endif</article>
@empty<div class="empty-card"><p>{{ $en ? 'No bookings in this browser session.' : '此瀏覽器工作階段尚未建立預約。' }}</p><a class="action-link" href="/?lang={{ $en ? 'en' : 'zh' }}">{{ $en ? 'Book a visit' : '線上預約' }}</a></div>@endforelse
</main></body></html>
