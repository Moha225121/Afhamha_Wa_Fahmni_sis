<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title') | افهمها وفهمني</title><link rel="stylesheet" href="{{ asset('css/admin.css') }}"><link rel="stylesheet" href="{{ asset('css/teacher-overrides.css') }}"></head>
<body><button class="menu-toggle" id="menu" aria-label="فتح القائمة">☰</button>
<aside class="sidebar" id="sidebar"><a class="logo" href="{{ route('teacher.dashboard') }}"><span class="brand-mark">أف</span><span><b>افهمها وفهمني</b><small>بوابة المعلم</small></span></a>
<nav>@php($nav=['dashboard'=>['label'=>'الرئيسية','code'=>'01'],'students'=>['label'=>'الطلاب','code'=>'03'],'assignments'=>['label'=>'الواجبات','code'=>'05'],'exams'=>['label'=>'الاختبارات','code'=>'06'],'grades'=>['label'=>'الدرجات','code'=>'08'],'attendance'=>['label'=>'الحضور','code'=>'07']])
@foreach($nav as $key=>$item)
<a href="{{ route('teacher.'.$key.'.index') }}" class="{{ request()->routeIs('teacher.'.$key.'*')?'active':'' }}"><i>{{ $item['code'] }}</i>{{ $item['label'] }}</a>
@endforeach
</nav><div class="partner">الشريك التقني<br><b>HEXA.Tech</b></div></aside>
<div class="page"><header class="topbar"><form class="global-search"><input aria-label="بحث" placeholder="ابحث في المنصة..."></form><div class="account"><a href="{{ route('teacher.profile.edit') }}" class="profile-link">{{ auth()->user()->name }}</a><form method="post" action="{{ route('logout') }}">@csrf<button>خروج</button></form></div></header>
<main class="content">@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error"><b>يرجى تصحيح الأخطاء:</b><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="page-head"><div><p class="eyebrow">بوابة المعلم</p><h1>@yield('title')</h1><p class="muted">@yield('subtitle')</p></div>@yield('actions')</div>@yield('content')</main></div>
<script>document.getElementById('menu').onclick=()=>document.getElementById('sidebar').classList.toggle('open');document.querySelectorAll('[data-confirm]').forEach(x=>x.onclick=e=>{if(!confirm(x.dataset.confirm))e.preventDefault()})</script>
@yield('scripts')
</body></html>
