<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title') | افهمها وفهمني</title><link rel="stylesheet" href="{{ asset('css/admin.css') }}"><script src="{{ asset('js/enhanced-selects.js') }}" defer></script></head>
<body><button class="menu-toggle" id="menu" aria-label="فتح القائمة">☰</button>
<aside class="sidebar" id="sidebar"><a class="logo" href="{{ route('admin.dashboard') }}"><span class="brand-mark">AWF</span><span><b>افهمها وفهمني</b><small>إدارة المدرسة</small></span></a>
<nav>@php($nav=['dashboard'=>'لوحة التحكم','students'=>'الطلاب','teachers'=>'المعلمون','parents'=>'أولياء الأمور','classes'=>'الصفوف','subjects'=>'المواد','schedules'=>'الجداول','attendance'=>'الحضور','exams'=>'الاختبارات','grades'=>'الدرجات','library'=>'المكتبة','announcements'=>'الإعلانات','reports'=>'التقارير','users'=>'المستخدمون','roles'=>'الصلاحيات','audit-logs'=>'سجل العمليات','settings'=>'الإعدادات'])
@foreach($nav as $key=>$label)
@php($url=in_array($key,['dashboard','students','teachers','parents','classes','subjects','announcements'])?route('admin.'.$key.'.index',[],false):route('admin.module.index',$key,false))
<a href="{{ $url }}" class="{{ request()->is('admin/'.$key.'*')?'active':'' }}"><i>{{ str_pad($loop->iteration,2,'0',STR_PAD_LEFT) }}</i>{{ $label }}</a>
@endforeach</nav><div class="partner">الشريك التقني<br><b>HEXA.Tech</b></div></aside>
<div class="page"><header class="topbar"><form class="global-search"><input aria-label="بحث" placeholder="ابحث في المنصة..."></form><div class="account"><a href="{{ route('admin.module.index','notifications') }}" aria-label="الإشعارات">◉</a><a href="{{ route('admin.profile.edit') }}">{{ auth()->user()->name }}</a><form method="post" action="{{ route('logout') }}">@csrf<button>خروج</button></form></div></header>
<main class="content">@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error"><b>يرجى تصحيح الأخطاء:</b><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="page-head"><div><p class="eyebrow">إدارة المدرسة</p><h1>@yield('title')</h1><p class="muted">@yield('subtitle')</p></div>@yield('actions')</div>@yield('content')</main></div>
<script>document.getElementById('menu').onclick=()=>document.getElementById('sidebar').classList.toggle('open');document.querySelectorAll('[data-confirm]').forEach(x=>x.onclick=e=>{if(!confirm(x.dataset.confirm))e.preventDefault()})</script></body></html>
