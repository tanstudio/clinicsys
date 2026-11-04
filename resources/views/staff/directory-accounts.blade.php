@php
    $roles = ['doctor'=>'醫師','reception'=>'前台','hr'=>'人事'];
    if (\App\Support\StaffAccess::hq()) $roles['admin']='分店管理員';
@endphp
<h2>新增職員帳號</h2>
@include('staff.account-form',['row'=>null])
<h2>本分店職員</h2>
@forelse($entries as $row)
<article><strong>{{ $row->name }}</strong><p>{{ $row->email }} · {{ $roles[$row->role] ?? '分店管理員' }} · {{ $row->active ? '已啟用' : '已停用' }}</p>
@if(isset($roles[$row->role]) && $row->id !== \App\Support\StaffAccess::id())
<details><summary>編輯帳號</summary>@include('staff.account-form')</details>
@endif</article>
@empty<p>本分店暫無職員帳號。</p>@endforelse
