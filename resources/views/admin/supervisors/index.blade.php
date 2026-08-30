@extends('admin.layout')
@section('title','المشرفون')
@section('actions')<a class="btn primary" href="{{ route('admin.supervisors.create') }}">إضافة مشرف</a>@endsection
@section('content')
<form class="filters"><input name="q" value="{{ request('q') }}" placeholder="الاسم أو البريد"><select name="status"><option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">موقوف</option></select><button class="btn secondary">بحث</button></form>
<div class="table-wrap"><table><thead><tr><th>الاسم</th><th>البريد</th><th>الهاتف</th><th>الفصول المكلف بها</th><th>الحالة</th><th></th></tr></thead><tbody>@forelse($supervisors as $supervisor)<tr><td>{{ $supervisor->name }}</td><td>{{ $supervisor->email }}</td><td>{{ $supervisor->phone ?: '—' }}</td><td>{{ $supervisor->supervisedClassrooms->map(fn($c)=>$c->name.($c->section?' - '.$c->section:''))->join('، ') ?: '—' }}</td><td><span class="badge {{ $supervisor->status }}">{{ $supervisor->status==='active'?'نشط':'موقوف' }}</span></td><td><a href="{{ route('admin.supervisors.edit',$supervisor) }}">تعديل</a></td></tr>@empty<tr><td colspan="6"><div class="empty">لا يوجد مشرفون حاليًا.</div></td></tr>@endforelse</tbody></table></div>{{ $supervisors->links() }}
@endsection
