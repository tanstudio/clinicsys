@extends('staff.layout')
@section('title', '會員及套票規則')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'loyalty'])</span><h1>會員及套票規則</h1></header>
<p>總部在此設定會員等級及療程套票方案。修改只影響之後的收款及售出；已售出的套票保留售出時的次數、價錢及有效期。</p>

<h2>會員等級</h2>
<p>患者等級按已付收據計算：在各等級的計算月數內，累計實收達門檻即升級；取最高門檻的已啟用等級。總部亦可在患者檔案手動指定等級。折扣只作收銀提示，實收金額由收銀確認。</p>
@foreach($tiers as $tier)
<form method="post" action="/staff/loyalty/tiers/{{ $tier->id }}" class="rule-card">@csrf
<h3>{{ $tier->name }} · {{ $tier->active ? '啟用' : '停用' }}</h3>
<label>等級名稱 <input name="name" value="{{ $tier->name }}" maxlength="40" required></label>
<label>門檻（HK$） <input type="number" name="threshold" step="0.01" min="0" value="{{ $tier->threshold_cents / 100 }}" required></label>
<label>計算月數 <input type="number" name="window_months" min="1" max="60" value="{{ $tier->window_months }}" required></label>
<label>診金折扣（%） <input type="number" name="discount_percent" min="0" max="100" value="{{ $tier->discount_percent }}" required></label>
<label>狀態 <select name="active"><option value="1" @selected($tier->active)>啟用</option><option value="0" @selected(!$tier->active)>停用</option></select></label>
<button>儲存等級</button></form>
@endforeach
@if($tiers->isEmpty())<p>尚未設定會員等級。</p>@endif
<h3>新增等級</h3>
<form method="post" action="/staff/loyalty/tiers">@csrf
<label>等級名稱 <input name="name" maxlength="40" required></label>
<label>門檻（HK$） <input type="number" name="threshold" step="0.01" min="0" required></label>
<label>計算月數 <input type="number" name="window_months" min="1" max="60" value="12" required></label>
<label>診金折扣（%） <input type="number" name="discount_percent" min="0" max="100" value="0" required></label>
<input type="hidden" name="active" value="1"><button>新增等級</button></form>

<h2>療程套票方案</h2>
<p>每個方案適用一項服務。售出後的總次數＝次數＋贈送次數，有效期由售出當日起計。只有勾選的分店可出售及使用。</p>
@foreach($plans as $plan)
<form method="post" action="/staff/loyalty/plans/{{ $plan->id }}" class="rule-card">@csrf
<h3>{{ $plan->name }} · {{ $plan->active ? '啟用' : '停用' }}</h3>
<label>方案名稱 <input name="name" value="{{ $plan->name }}" maxlength="80" required></label>
<label>適用服務 <select name="service_id">@foreach($services as $id=>$name)<option value="{{ $id }}" @selected($plan->service_id == $id)>{{ $name }}</option>@endforeach</select></label>
<label>次數 <input type="number" name="sessions" min="1" max="999" value="{{ $plan->sessions }}" required></label>
<label>贈送次數 <input type="number" name="bonus_sessions" min="0" max="999" value="{{ $plan->bonus_sessions }}" required></label>
<label>售價（HK$） <input type="number" name="price" step="0.01" min="0" value="{{ $plan->price_cents / 100 }}" required></label>
<label>有效日數 <input type="number" name="validity_days" min="1" max="3650" value="{{ $plan->validity_days }}" required></label>
<fieldset><legend>適用分店</legend>@foreach($branches as $id=>$name)<label><input type="checkbox" name="branches[]" value="{{ $id }}" @checked(in_array($id, $planBranches[$plan->id] ?? []))> {{ $name }}</label>@endforeach</fieldset>
<label>狀態 <select name="active"><option value="1" @selected($plan->active)>啟用</option><option value="0" @selected(!$plan->active)>停用</option></select></label>
<button>儲存方案</button></form>
@endforeach
@if($plans->isEmpty())<p>尚未設定療程套票方案。</p>@endif
<h3>新增方案</h3>
<form method="post" action="/staff/loyalty/plans" class="rule-card">@csrf
<label>方案名稱 <input name="name" maxlength="80" required></label>
<label>適用服務 <select name="service_id">@foreach($services as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
<label>次數 <input type="number" name="sessions" min="1" max="999" required></label>
<label>贈送次數 <input type="number" name="bonus_sessions" min="0" max="999" value="0" required></label>
<label>售價（HK$） <input type="number" name="price" step="0.01" min="0" required></label>
<label>有效日數 <input type="number" name="validity_days" min="1" max="3650" value="365" required></label>
<fieldset><legend>適用分店</legend>@foreach($branches as $id=>$name)<label><input type="checkbox" name="branches[]" value="{{ $id }}" checked> {{ $name }}</label>@endforeach</fieldset>
<input type="hidden" name="active" value="1"><button>新增方案</button></form>
@endsection
