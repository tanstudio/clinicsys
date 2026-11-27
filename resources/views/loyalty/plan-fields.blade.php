@php($p = $plan ?? null)
<label>方案名稱 <input name="name" value="{{ $p->name ?? '' }}" maxlength="80" required></label>
<label>方案類型 <select name="kind">@foreach(\App\Support\Loyalty::KINDS as $key=>$label)<option value="{{ $key }}" @selected(($p->kind ?? 'sessions') === $key)>{{ $label }}</option>@endforeach</select></label>
<fieldset><legend>療程次數方案填寫</legend>
<label>適用服務 <select name="service_id"><option value="">—</option>@foreach($services as $id=>$name)<option value="{{ $id }}" @selected(($p->service_id ?? null) == $id)>{{ $name }}</option>@endforeach</select></label>
<label>次數 <input type="number" name="sessions" min="1" max="999" value="{{ ($p->sessions ?? 0) ?: '' }}"></label>
<label>贈送次數 <input type="number" name="bonus_sessions" min="0" max="999" value="{{ $p->bonus_sessions ?? 0 }}"></label>
</fieldset>
<fieldset><legend>儲值方案填寫</legend>
<label>儲值金額（HK$） <input type="number" name="value" step="0.01" min="0.01" value="{{ ($p->value_cents ?? 0) ? $p->value_cents / 100 : '' }}"></label>
<label>贈送金額（HK$） <input type="number" name="bonus_value" step="0.01" min="0" value="{{ ($p->bonus_value_cents ?? 0) / 100 }}"></label>
</fieldset>
<label>售價（HK$） <input type="number" name="price" step="0.01" min="0" value="{{ isset($p) ? $p->price_cents / 100 : '' }}" required></label>
<label>有效日數 <input type="number" name="validity_days" min="1" max="3650" value="{{ $p->validity_days ?? 365 }}" required></label>
<label>可否退款 <select name="refundable"><option value="1" @selected($p->refundable ?? true)>可退款</option><option value="0" @selected(!($p->refundable ?? true))>不設退款</option></select></label>
<label>退款手續費（%） <input type="number" name="refund_fee_percent" min="0" max="100" value="{{ $p->refund_fee_percent ?? 0 }}" required></label>
<label>可否轉讓 <select name="transferable"><option value="0" @selected(!($p->transferable ?? false))>不可轉讓</option><option value="1" @selected($p->transferable ?? false)>可轉讓給其他患者</option></select></label>
<fieldset><legend>適用分店</legend>@foreach($branches as $id=>$name)<label><input type="checkbox" name="branches[]" value="{{ $id }}" @checked(isset($p) ? in_array($id, $planBranches[$p->id] ?? []) : true)> {{ $name }}</label>@endforeach</fieldset>
