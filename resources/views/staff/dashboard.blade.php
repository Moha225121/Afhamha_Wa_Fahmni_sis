@extends('staff.layout')
@section('title','لوحة الموظف')
@section('subtitle','الشاشات المتاحة حسب الأدوار والصلاحيات المسندة إلى حسابك.')
@section('content')<div class="staff-dashboard-grid">@forelse($modules as $key=>$module)@php($moduleUrl=$key==='notifications'?route('staff.notifications'):route('staff.module',$key))<a class="staff-module-card" href="{{ $moduleUrl }}"><h2>{{ $module['label'] }}</h2><p>{{ $module['description'] }}</p><span>فتح البوابة ←</span></a>@empty<div class="panel staff-empty">لا توجد صلاحيات مسندة إلى حسابك حاليًا.</div>@endforelse</div>@endsection
