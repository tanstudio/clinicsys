@extends('staff.layout')
@section('title', '對話記錄')
@section('content')
<a href="/staff/inbox">訊息收件匣</a><header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'inbox'])</span><h1>{{ \Illuminate\Support\Facades\Crypt::decryptString($thread->phone) }}</h1></header>
<form method="post" action="/staff/inbox/{{ $thread->id }}/assign">@csrf
<label>負責職員 <select name="assignee_id"><option value="">未指派</option>@foreach($agents as $id=>$name)<option value="{{ $id }}" @selected($thread->assignee_id == $id)>{{ $name }}</option>@endforeach</select></label>
<label>類別 <input name="category" value="{{ $thread->category }}" maxlength="80" required></label>
<label>狀態 <select name="status">@foreach(['open'=>'待跟進','closed'=>'已結案'] as $status=>$label)<option value="{{ $status }}" @selected($thread->status === $status)>{{ $label }}</option>@endforeach</select></label>
<label>接待方式 <select name="automation_paused"><option value="1" @selected($thread->automation_paused)>職員處理，暫停自動回覆</option><option value="0" @selected(!$thread->automation_paused)>自動接待（需取消職員指派）</option></select></label>
<button>更新對話</button></form>
@if(!$thread->patient_id)
<details><summary>連結患者 CRM</summary>
<form method="post" action="/staff/inbox/{{ $thread->id }}/link">@csrf
<label>本分店預約編號 <input name="reference" required></label>
<label><input type="checkbox" name="verified" value="1" required> 已核實聯絡人身分</label><button>連結</button></form></details>
@else<p>已連結患者 #{{ $thread->patient_id }}</p>@endif
<h2>回覆</h2>
<p>一般回覆限患者最後來訊後 24 小時內。超時訊息會停止發送，通知需使用核准範本。</p>
<form method="post" action="/staff/inbox/{{ $thread->id }}/reply">@csrf
<input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>WhatsApp 回覆 <textarea name="body" required maxlength="2000"></textarea></label><button>排程回覆</button></form>
<h2>記錄</h2>
@forelse($entries as $e)
<article><p><strong>{{ $e->direction === 'inbound' ? '收到' : '發出' }} · {{ $e->channel }}</strong> {{ $e->created_at }} · {{ $e->kind }} · {{ ['queued'=>'等待發送','sending'=>'發送中','accepted'=>'平台已接受','sent'=>'已發送','delivered'=>'已送達','read'=>'已讀','failed'=>'發送失敗','review'=>'需核對發送結果','unconfigured'=>'通道或範本未設定','suppressed'=>'已停止發送','superseded'=>'已被最新通知取代','received'=>'已收到'][$e->status] ?? $e->status }}</p>
<p style="white-space:pre-wrap">{{ \Illuminate\Support\Facades\Crypt::decryptString($e->body) }}</p>
@if($e->error)<small>{{ $e->error }}</small>@endif
@if(\App\Support\StaffAccess::role() === 'admin' && in_array($e->status,['failed','review','unconfigured']))
<form method="post" action="/staff/inbox/{{ $thread->id }}/retry/{{ $e->id }}">@csrf
<label><input type="checkbox" name="checked" value="1" required>已核對供應商未接受此訊息</label><button>重試</button></form>@endif
</article>
@empty<p>尚無紀錄。</p>@endforelse
@include('messaging.pages',['page'=>$entries])
@endsection
