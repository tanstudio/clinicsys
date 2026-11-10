@extends('staff.layout')
@section('title', '員工檔案')
@push('styles')
<style>
.hr-section{border-top:1px solid var(--line);margin-top:26px;scroll-margin-top:18px}
.hr-section form{display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin:14px 0}
.hr-section label{display:grid;gap:5px;min-width:min(180px,100%);max-width:100%}
.hr-section article{border-left:3px solid var(--brand-tint);padding:0 0 0 16px;margin:18px 0}
.hr-section article h3,.hr-section article p{margin:4px 0}
.hr-section article p,.hr-section li{overflow-wrap:anywhere}
.hr-section nav svg{width:1.1em;height:1.1em}
</style>
@endpush
@section('content')
@php
    $url = url('/staff/hr/people/'.$person->id);
    $historyTypes = ['job'=>'職務變動','pay'=>'薪酬調整','training'=>'培訓','performance'=>'表現評核'];
    $docTypes = ['contract'=>'僱傭合約','tax'=>'稅務文件'];
    $leaveTypes = ['annual'=>'年假','sick'=>'病假','maternity'=>'產假'];
    $statuses = ['approved'=>'已批准','rejected'=>'已拒絕','pending'=>'待審批'];
@endphp
<a href="{{ url('/staff/hr') }}">員工名單</a>
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'hr'])</span><h1>{{ $person->name }}</h1></header>
<p>{{ $person->email }} · {{ $person->role }}</p>
@if(session('hr_status'))<p role="status">{{ session('hr_status') }}</p>@endif
<nav aria-label="員工檔案內容"><a href="#profile">聯絡資料</a> · <a href="#history">任職紀錄</a> · <a href="#documents">文件</a> · <a href="#shifts">考勤</a> · <a href="#leave">假期</a></nav>
<section class="hr-section" id="profile">
    <h2>身份及聯絡資料</h2>
    <form method="post" action="{{ $url }}/profile">@csrf
        @foreach(['identity'=>'身份證明號碼','phone'=>'聯絡電話','address'=>'住址','emergency_name'=>'緊急聯絡人','emergency_phone'=>'緊急聯絡電話'] as $field=>$label)
            <label>{{ $label }} <input name="{{ $field }}" value="{{ $profile[$field] ?? '' }}" autocomplete="off"></label>
        @endforeach
        <button>儲存資料</button>
    </form>
</section>
<section class="hr-section" id="history">
    <h2>任職紀錄</h2>
    @if($management)
        <form method="post" action="{{ $url }}/history">@csrf
            <label>類別 <select name="kind">@foreach($historyTypes as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label>生效日期 <input type="date" name="effective_on" required></label>
            <label>紀錄內容 <textarea name="details" required maxlength="3000"></textarea></label>
            <button>新增紀錄</button>
        </form>
    @endif
    <ul>@forelse($history as $entry)
        <li>{{ $entry->effective_on }} · {{ $historyTypes[$entry->kind] }} · {{ Illuminate\Support\Facades\Crypt::decryptString($entry->details) }}</li>
    @empty<li>暫無任職紀錄。</li>@endforelse</ul>
    {{ $history->links() }}
</section>
<section class="hr-section" id="documents">
    <h2>合約及稅務文件</h2>
    @if($management)
        <form method="post" action="{{ $url }}/documents" enctype="multipart/form-data">@csrf
            <label>文件類別 <select name="kind">@foreach($docTypes as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label>檔案（PDF、PNG 或 JPEG，最多 2 MB）<input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg" required></label>
            <button>上載文件</button>
        </form>
    @endif
    <ul>@forelse($documents as $doc)
        <li>{{ $docTypes[$doc->kind] }} · <a href="{{ $url }}/documents/{{ $doc->id }}" download>{{ Illuminate\Support\Facades\Crypt::decryptString($doc->filename) }}</a></li>
    @empty<li>暫無文件。</li>@endforelse</ul>
</section>
<section class="hr-section" id="shifts">
    <h2>更表及考勤</h2>
    <p>所有時間均為香港時間。彈性更次按實際到達時間及規定工時計算預計下班時間。</p>
    @if($management)
        <form method="post" action="{{ $url }}/shifts">@csrf
            <label>更次 <select name="kind"><option value="fixed">固定更</option><option value="flex">彈性更</option></select></label>
            <label>開始時間 <input type="datetime-local" name="starts_at" required></label>
            <label>最遲上班（彈性更必填）<input type="datetime-local" name="latest_start"></label>
            <label>規定工時（分鐘）<input type="number" min="1" max="1440" value="480" name="required_minutes" required></label>
            <button>新增更次</button>
        </form>
    @endif
    @forelse($shifts as $shift)
        <article>
            <h3>{{ $shift->starts_at }} · {{ $shift->kind === 'flex' ? '彈性更' : '固定更' }}</h3>
            <p>最遲上班 {{ $shift->latest_start }} · 規定工時 {{ $shift->required_minutes }} 分鐘</p>
            <p>上班 {{ $shift->clock_in ?? '—' }} · 下班 {{ $shift->clock_out ?? '—' }}</p>
            <p>遲到 {{ $shift->attendance['late'] ?? '—' }} 分鐘 · 早退 {{ $shift->attendance['early'] ?? '—' }} 分鐘 · 加班 {{ $shift->attendance['overtime'] ?? '—' }} 分鐘</p>
            @if($own && !$shift->clock_out)
                <form method="post" action="{{ $url }}/shifts/{{ $shift->id }}/clock">@csrf
                    <button name="action" value="{{ $shift->clock_in ? 'out' : 'in' }}">{{ $shift->clock_in ? '下班打卡' : '上班打卡' }}</button>
                </form>
            @endif
        </article>
    @empty<p>暫無更次。</p>@endforelse
    {{ $shifts->links() }}
</section>
<section class="hr-section" id="leave">
    <h2>假期申請</h2>
    @if($own)
        <form method="post" action="{{ $url }}/leave">@csrf
            <label>假別 <select name="kind">@foreach($leaveTypes as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label>首日 <input type="date" name="starts_on" required></label>
            <label>末日 <input type="date" name="ends_on" required></label>
            <label>事由 <textarea name="reason" required maxlength="1500"></textarea></label>
            <button>提交申請</button>
        </form>
    @endif
    @forelse($leave as $entry)
        <article>
            <h3>{{ $entry->starts_on }} 至 {{ $entry->ends_on }} · {{ $leaveTypes[$entry->kind] }}</h3>
            <p>{{ $statuses[$entry->status] }} · {{ Illuminate\Support\Facades\Crypt::decryptString($entry->reason) }}</p>
            @if($management && !$own && $entry->status === 'pending')
                <form method="post" action="{{ $url }}/leave/{{ $entry->id }}/review">@csrf
                    <button name="decision" value="approved">批准</button>
                    <button name="decision" value="rejected">拒絕</button>
                </form>
            @endif
        </article>
    @empty<p>暫無假期申請。</p>@endforelse
    {{ $leave->links() }}
</section>
@endsection
