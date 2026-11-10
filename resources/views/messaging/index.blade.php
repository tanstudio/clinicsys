@extends('staff.layout')
@section('title', '訊息收件匣')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'inbox'])</span><h1>訊息收件匣</h1></header>
<nav>@if(\App\Support\StaffAccess::hq())<a href="?scope=all">全線分店對話</a> · @endif<a href="?">分店對話</a> · <a href="?queue=open">待跟進</a> · <a href="?queue=mine">指派給我</a></nav>
@if(\App\Support\StaffAccess::hq())<p><a href="/staff/whatsapp">WhatsApp 通道與通知範本</a></p>@endif
<div class="sheet-scroll"><table><thead><tr><th>分店</th><th>聯絡電話</th><th>類別</th><th>負責職員</th><th>狀態</th><th>最近更新（香港時間）</th></tr></thead><tbody>
@forelse($threads as $t)<tr><td>{{ $branches[$t->branch_id] }}</td><td><a href="/staff/inbox/{{ $t->id }}">{{ \Illuminate\Support\Facades\Crypt::decryptString($t->phone) }}</a></td><td>{{ $t->category }}</td><td>{{ $t->agent_name ?? '未指派' }}</td><td>{{ $t->status }}</td><td>{{ \Illuminate\Support\Carbon::parse($t->updated_at, 'UTC')->tz('Asia/Hong_Kong')->format('Y-m-d H:i') }}</td></tr>
@empty<tr><td colspan="6">此佇列尚無對話。</td></tr>@endforelse
</tbody></table></div>
@include('messaging.pages',['page'=>$threads])
@if(\App\Support\StaffAccess::role() === 'admin')
<h2>關鍵字分流和自動回覆</h2>
<p>依建立順序套用第一條符合的規則。用 | 分隔同義問法，例如「營業時間|幾點開門|opening hours」。指派職員後暫停自動接待；未命中的問題轉交職員。</p>
@foreach($rules as $r)
<form method="post" action="/staff/inbox/rules/{{ $r->id }}/delete">@csrf <strong>{{ $r->keyword }}</strong> · {{ $r->category }} · {{ $agents[$r->assignee_id] ?? '未指派' }} · {{ $r->reply }} <button>刪除</button></form>
@endforeach
<form method="post" action="/staff/inbox/rules">@csrf
<label>關鍵字 <input name="keyword" maxlength="100" required></label>
<label>查詢類別 <input name="category" maxlength="80" required></label>
<label>負責職員 <select name="assignee_id"><option value="">未指派</option>@foreach($agents as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
<label>自動回覆 <textarea name="reply" maxlength="2000"></textarea></label><button>儲存規則</button></form>
<h2>分店推廣</h2><p>只發送給已回覆 START 或「訂閱」的聯絡人。回覆 STOP 或「退訂」即可退出。</p>
<form method="post" action="/staff/inbox/campaign">@csrf
<input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>推廣內容 <textarea name="body" maxlength="1000" required></textarea></label><button>排程發送</button></form>
@endif
@endsection
