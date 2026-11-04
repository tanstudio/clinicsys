@extends('staff.layout')
@section('title','職員登入')
@section('content')
<header class="module-heading"><span class="module-tile">@include('staff.icon', ['module'=>'login'])</span><h1>職員登入</h1></header>
<form class="portal-login" method="post" action="/staff/login">@csrf
<label>工作電郵<input name="email" type="email" autocomplete="username" value="{{ old('email') }}" required></label>
<label>密碼<input name="password" type="password" autocomplete="current-password" required></label>
<button>登入工作台</button>
</form>@endsection
