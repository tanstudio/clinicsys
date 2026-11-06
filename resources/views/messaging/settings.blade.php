@extends('staff.layout')
@section('title', 'WhatsApp 設定')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'inbox'])</span><h1>WhatsApp 設定</h1></header>
<p>直接連接 Meta 官方 Cloud API。全線分店共用業務號碼，對話依分店權限分開處理。</p>
@if($branches->isNotEmpty())<nav><a href="/staff/inbox">查看訊息收件匣</a></nav>@endif
<h2>通道配置</h2>
<p>以下只顯示伺服器設定是否已填寫，不代表已通過手機聯測。密鑰不會顯示於此頁。</p>
<div class="sheet-scroll"><table><thead><tr><th>配置項目</th><th>狀態</th></tr></thead><tbody>
@foreach(['access_token'=>'存取權杖','app_secret'=>'應用程式密鑰','verify_token'=>'Webhook 驗證密鑰','phone_number_id'=>'業務號碼 ID','business_account_id'=>'WhatsApp 企業帳戶 ID','graph_version'=>'Graph API 版本'] as $key=>$label)
<tr><td>{{ $label }}</td><td>{{ $configured[$key] ? '已填寫' : '等待設定' }}</td></tr>
@endforeach
</tbody></table></div>
<p>回調網址：<code style="overflow-wrap:anywhere">{{ url('/webhooks/whatsapp') }}</code></p>
<h2>新對話接待分店</h2>
<p>新查詢先進入指定分店。患者可輸入 BRANCHES 查詢分店，再用 BOOK@分店編號 選擇預約分店。</p>
@if($branches->isEmpty())<p>請先<a href="/staff/branches">新增分店</a>，再設定接待分店。</p>@else
<form method="post" action="/staff/whatsapp" aria-label="接待分店">@csrf
<label>接待分店 <select name="default_branch_id"><option value="">暫不接收新對話</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($defaultBranch == $branch->id)>{{ $branch->name_zh }}</option>@endforeach</select></label><button>儲存接待分店</button>
</form>@endif
<h2>通知範本</h2>
<p>先在 Meta 核准範本，再填寫名稱、語言及內文變數。變數以逗號分隔，順序對應 @verbatim{{1}}、{{2}}@endverbatim。未啟用的範本不會發送。</p>
<p>可用變數：reference＝預約或收據編號；starts_at＝香港預約時間；branch_name＝分店名稱；amount＝收款金額；body＝推廣內容。此版本支援純文字內文及固定文字範本。</p>
@foreach($kinds as $kind=>$label)
@php($template = $templates[$kind] ?? null)
<details><summary>{{ $label }} · {{ $template?->approved ? '已啟用' : '未啟用' }}</summary>
<form method="post" action="/staff/whatsapp/templates" aria-label="{{ $label }}範本">@csrf
<input type="hidden" name="kind" value="{{ $kind }}">
<label>Meta 範本名稱 <input name="name" value="{{ $template?->name }}" pattern="[a-z0-9_]+" required maxlength="512"></label>
<label>語言代碼 <input name="language" value="{{ $template?->language }}" placeholder="例如 zh_HK" required maxlength="20"></label>
<label>內文變數順序 <input name="parameters" value="{{ $template ? implode(',',json_decode($template->parameters,true)) : '' }}" maxlength="200"></label>
<small>此通知可用：{{ implode(', ', $parameters[$kind]) }}。固定文字範本可留空。</small>
<label>發送狀態 <select name="approved"><option value="0">停用</option><option value="1" @selected($template?->approved)>已在 Meta 核准，啟用發送</option></select></label>
<button>儲存{{ $label }}範本</button></form></details>
@endforeach
@endsection
