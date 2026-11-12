@extends('admin.layout')
@section('title','الإعلانات')
@section('actions')<a class="btn primary" href="{{ route('admin.announcements.create') }}">إعلان جديد</a>@endsection
@section('content')
@php
    $audiences = ['all' => 'الجميع', 'students' => 'الطلاب', 'teachers' => 'المعلمون', 'parents' => 'أولياء الأمور', 'supervisors' => 'المشرفون', 'staff' => 'الموظفون', 'classroom' => 'صف محدد'];
    $statuses = ['draft' => 'مسودة', 'published' => 'منشور / مجدول', 'archived' => 'مؤرشف'];
@endphp
<form class="form-card" method="get" action="{{ route('admin.announcements.index') }}">
    <div class="form-grid">
        <label>بحث في العنوان والمحتوى<input name="search" value="{{ request('search') }}"></label>
        <label>الجمهور<select name="audience"><option value="">كل الجماهير</option>@foreach($audiences as $value => $label)<option value="{{ $value }}" @selected(request('audience') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>الحالة<select name="status"><option value="">كل الحالات</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>الصف / الشعبة<select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected((string) request('classroom_id') === (string) $classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select></label>
    </div>
    <div class="form-actions"><button class="btn primary">بحث</button><a class="btn" href="{{ route('admin.announcements.index') }}">مسح الفلاتر</a></div>
</form>
<p class="muted">الأوقات بتوقيت {{ \App\Support\DisplayTimezone::name() }}. الأرشفة توقف الظهور والتوصيل المعلّق وتحفظ سجل الإشعارات السابقة.</p>
<div class="table-wrap"><table><thead><tr><th>العنوان</th><th>الجمهور</th><th>النشر</th><th>الانتهاء</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
@forelse($announcements as $a)
<tr><td><a href="{{ route('admin.announcements.show', $a) }}">{{ $a->title }}</a></td><td>{{ $audiences[$a->audience] ?? $a->audience }}</td>
<td>{{ $a->published_at?->copy()->timezone(\App\Support\DisplayTimezone::name())->format('Y-m-d H:i') ?? 'فوري عند النشر' }}</td>
<td>{{ $a->expires_at?->copy()->timezone(\App\Support\DisplayTimezone::name())->format('Y-m-d H:i') ?? '—' }}</td>
<td><span class="badge {{ $a->status }}">{{ $a->status === 'published' && $a->published_at?->isFuture() ? 'مجدول' : ($statuses[$a->status] ?? $a->status) }}</span></td>
<td><a href="{{ route('admin.announcements.edit', $a) }}">تعديل</a>
@if($a->status !== 'archived')<form method="post" action="{{ route('admin.announcements.archive', $a) }}">@csrf @method('patch')<button class="btn">أرشفة وإيقاف النشر</button></form>@endif
@if($a->status === 'draft')<form method="post" action="{{ route('admin.announcements.destroy', $a) }}" onsubmit="return confirm('حذف هذه المسودة؟ لا يمكن حذف إعلان له سجل إشعارات.')">@csrf @method('delete')<button class="btn">حذف المسودة</button></form>@endif
</td></tr>
@empty<tr><td colspan="6"><div class="empty">لا توجد إعلانات مطابقة.</div></td></tr>@endforelse
</tbody></table></div>
{{ $announcements->links() }}
@endsection
