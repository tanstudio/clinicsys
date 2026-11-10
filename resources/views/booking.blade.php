<!doctype html>
<html lang="zh-HK">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}"><title>線上預約</title>
<link rel="stylesheet" href="{{ \App\Support\Asset::url('booking.css') }}"><script defer src="{{ \App\Support\Asset::url('assets/booking.js') }}"></script><script defer src="{{ \App\Support\Asset::url('assets/enhance-selects.js') }}"></script>
</head>
<body x-data="booking" data-reference="{{ $appointment->reference ?? '' }}" data-expected-slot="{{ $appointment->slot_id ?? '' }}">
<header><div class="wrap nav"><div class="brand"><svg viewBox="0 0 32 32" aria-hidden="true"><path fill="currentColor" d="M0 10h10V0h12v10h10v12H22v10H10V22H0z"/></svg><b x-text="t('brand')">中醫門診</b></div><div class="nav-actions"><a :href="'/appointments/manage?lang='+lang" @if($embed ?? false) target="_blank" rel="noopener" @endif x-text="t('manage')"></a><button class="language" @click="toggle" x-text="lang==='zh'?'English':'繁體中文'">English</button></div></div></header>
<main class="wrap">
<div class="hero">@include('patient-calendar-icon')<div><h1 x-text="t(phase===3?(moving?'changed':'done'):(moving?'move':'title'))">線上預約</h1><p x-text="t(phase===3?'receipt':(moving?'moveIntro':'intro'))"></p>@isset($originalTime)<p class="original" x-show="phase!==3"><strong x-text="t('original')"></strong> · {{ substr($originalTime,0,16) }}</p>@endisset</div>@if(app()->environment('local', 'testing'))<span class="sample" x-text="t('sample')"></span>@endif</div>
<div class="layout" x-cloak>
<section>
<div class="notice" role="alert" x-show="error" x-text="error?t(error):''"></div>
<p role="status" x-show="loading" x-text="t('loading')"></p>
<button x-show="error==='failed'" @click="refresh" x-text="t('retry')"></button>
<div x-show="phase===1 && !loading && error!=='failed'">
<h2><small>01</small><span x-text="t('visit')"></span></h2>
<div class="filters">
@foreach ([['branch','catalog.branches'],['service','services'],['doctor','doctors']] as [$field,$options])
<label><span x-text="t('{{ $field }}')"></span><select :value="{{ $field }}" @change="{{ $field }}=Number($event.target.value);align()">
<template x-for="o in {{ $options }}" :key="o.id"><option :value="o.id" :selected="o.id==={{ $field }}" x-text="label(o)"></option></template>
</select></label>
@endforeach
</div>
<h2><small>02</small><span x-text="t('dates')"></span></h2>
<div class="calendar" x-show="days.length">
<div class="dateline"><strong x-text="fmt(day,{year:'numeric',month:'long'})"></strong><span x-text="t('time')"></span></div>
<div class="days"><template x-for="d in days" :key="d"><button type="button" class="day" :aria-label="fmt(d,{month:'long',day:'numeric',weekday:'long'})" :aria-pressed="day===d" @click="day=d;slot=null"><span x-text="fmt(d,{weekday:'short'})"></span><b x-text="d.slice(-2)"></b></button></template></div>
<div class="slothead"><h3 x-text="fmt(day,{month:'long',day:'numeric',weekday:'long'})"></h3><span x-text="t('available')"></span></div>
<div class="times"><template x-for="s in times" :key="s.id"><button :aria-pressed="slot?.id===s.id" @click="slot=s" x-text="s.starts_at.slice(11,16)"></button></template></div>
</div><p x-show="!days.length" x-text="t('empty')"></p>
</div>
<div x-show="phase===2"><h2><small>03</small><span x-text="t('details')"></span></h2>
<form @submit.prevent="confirm"><label><span x-text="t('name')"></span><input x-model.trim="name" autocomplete="name" required maxlength="100"></label>
<label><span x-text="t('region')"></span><select x-model="country"><option value="852" x-text="t('hongKong')"></option><option value="86" x-text="t('mainland')"></option></select></label>
<label><span x-text="t(country==='86'?'mainlandPhone':'phone')"></span><div class="tel"><b x-text="'+'+country"></b><input x-model.trim="phone" autocomplete="tel-national" type="tel" inputmode="numeric" :pattern="country==='86'?'1[3-9][0-9]{9}':'[2-9][0-9]{7}'" :maxlength="country==='86'?11:8" required></div></label>
<label><span x-text="t('email')"></span><input x-model.trim="email" type="email" maxlength="254" autocomplete="email"></label>
<p><a :href="'/privacy?lang='+lang" target="_blank" rel="noopener" x-text="t('privacy')"></a></p>
<label class="opt-in"><input type="checkbox" x-model="whatsapp"><span x-text="t('whatsapp')"></span></label>
<button class="primary" :disabled="busy" x-text="t(busy?'pending':'submit')"></button></form>
<button class="link" :disabled="busy" @click="phase=1;error=''" x-text="t('back')"></button></div>
<div x-show="phase===3"><h2 x-text="t('ref')"></h2><p class="reference" x-text="reference"></p><a :href="'/?lang='+lang" x-text="t('again')"></a></div>
</section>
<aside><h2 x-text="t('summary')"></h2><dl>
@foreach ([['branch',"lookup('branches',branch)"],['service',"lookup('services',service)"],['doctor',"lookup('practitioners',doctor)"],['date',"day || t('choose')"],['hour',"slot?.starts_at.slice(11,16) || t('choose')"]] as [$key,$value])
<div><dt x-text="t('{{ $key }}')"></dt><dd x-text="{{ $value }}"></dd></div>
@endforeach
</dl>
@if($embed ?? false)
<a class="primary" x-show="slot" :href="'/?lang='+lang+'&slot='+slot?.id" target="_blank" rel="noopener" x-text="t('open')"></a>
@else
<button class="primary" x-show="phase===1 && moving" :disabled="!slot || busy" @click="confirm" x-text="t('confirmMove')"></button>
<button class="primary" x-show="phase===1 && !moving" :disabled="!slot" @click="phase=2;error='';$nextTick(()=>document.querySelector('input').focus())"><span x-text="t('next')"></span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-6-6 6 6-6 6"/></svg></button>
@endif
</aside>
</div></main>
</body></html>
