@extends('staff.layout')
@section('title', '會員及套票規則')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'loyalty'])</span><h1>會員及套票規則</h1></header>
<p>總部在此設定會員等級、療程套票、儲值方案及套票通用設定。修改只影響之後的收款及售出；已售出的套票保留售出時的次數、價錢及有效期。</p>

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

<h2>套票通用設定</h2>
<form method="post" action="/staff/loyalty/settings" class="rule-card">@csrf
<label>套票收入歸屬 <select name="revenue_basis">@foreach(\App\Support\Loyalty::BASES as $key=>$label)<option value="{{ $key }}" @selected($settings->revenue_basis === $key)>{{ $label }}</option>@endforeach</select></label>
<p>售出分店：售出當日全數確認給售出分店，退款在退款分店扣減。使用分店：每次使用按已付價錢攤分，確認給使用分店，撤銷扣減會同時沖回。只影響營運報表的「套票收入確認」，不改變收據的實收金額。</p>
<label>WhatsApp 餘額通知 <select name="balance_notices"><option value="1" @selected($settings->balance_notices)>開啟</option><option value="0" @selected(!$settings->balance_notices)>關閉</option></select></label>
<p>開啟後，購買、使用、撤銷、退款及轉讓都會向患者發送餘額通知；只發給最近一次預約同意 WhatsApp 通知的患者，並需在 WhatsApp 設定配置「套票餘額」範本。</p>
<button>儲存設定</button></form>

<h2>套票方案</h2>
<p>療程次數方案適用一項服務，總次數＝次數＋贈送次數。儲值方案可支付任何服務，總儲值＝儲值金額＋贈送金額。有效期由售出當日起計，只有勾選的分店可出售及使用。</p>
<p>退款按已付價錢計算剩餘部分：剩餘次數或儲值 ÷ 總次數或總儲值 × 售價，再扣退款手續費。轉讓把全部餘額轉給本分店另一名患者，有效期不變。售出後的條款以售出時為準。</p>
@foreach($plans as $plan)
<form method="post" action="/staff/loyalty/plans/{{ $plan->id }}" class="rule-card">@csrf
<h3>{{ $plan->name }} · {{ \App\Support\Loyalty::KINDS[$plan->kind] }} · {{ $plan->active ? '啟用' : '停用' }}</h3>
@include('loyalty.plan-fields', ['plan' => $plan])
<label>狀態 <select name="active"><option value="1" @selected($plan->active)>啟用</option><option value="0" @selected(!$plan->active)>停用</option></select></label>
<button>儲存方案</button></form>
@endforeach
@if($plans->isEmpty())<p>尚未設定套票方案。</p>@endif
<h3>新增方案</h3>
<form method="post" action="/staff/loyalty/plans" class="rule-card">@csrf
@include('loyalty.plan-fields', ['plan' => null])
<input type="hidden" name="active" value="1"><button>新增方案</button></form>
@endsection
