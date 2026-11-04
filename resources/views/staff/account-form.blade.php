<form method="post" action="/staff/accounts{{ $row ? '/'.$row->id : '' }}">@csrf
<input type="hidden" name="expected_branch" value="{{ $branch }}">
<label>姓名<input name="name" value="{{ $row?->name }}" required maxlength="255"></label>
<label>工作電郵<input type="email" name="email" value="{{ $row?->email }}" required maxlength="255"></label>
<label>職能<select name="role">@foreach($roles as $key=>$name)<option value="{{ $key }}" @selected($row?->role === $key)>{{ $name }}</option>@endforeach</select></label>
<label>帳號狀態<select name="active"><option value="1">啟用</option><option value="0" @selected($row && !$row->active)>停用</option></select></label>
<label>{{ $row ? '重設密碼（如不更改請留空）' : '初始密碼' }}<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" @required(!$row) placeholder="至少 12 個字元"></label>
<button>{{ $row ? '儲存帳號' : '新增帳號' }}</button></form>
