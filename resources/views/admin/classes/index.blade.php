@extends('admin.layout') @section('title','الصفوف') @section('actions')<a class="btn secondary" href="{{ route('admin.classes.sections') }}">إضافة تشعبات للصفوف من الأول إلى السادس</a> <a class="btn secondary" href="{{ route('admin.imports.create','classes') }}">استيراد الصفوف</a> <a class="btn secondary" href="{{ route('admin.imports.create','stages') }}">استيراد المراحل</a> <a class="btn primary" href="{{ route('admin.classes.create') }}">إضافة صف</a>@endsection @section('content')
<div class="alert warning" role="note"><strong>تنبيه قبل الحذف:</strong> لا يمكن حذف صف يحتوي على طلاب أو سجلات مرتبطة. حذف الصف الفارغ ينقله مؤقتًا إلى المحذوفات، ويمكنك استعادته مع روابط مواده.</div>
<div class="table-wrap"><table><thead><tr><th>الصف</th><th>المرحلة</th><th>السنة</th><th>الطلاب</th><th>المعلمون</th><th>المواد</th><th>الإجراءات</th></tr></thead><tbody>@forelse($classes as $c)<tr><td>{{ $c->name }} {{ $c->section }}</td><td>{{ $c->stage }}</td><td>{{ $c->academicYear->name }}</td><td>{{ $c->students_count }}</td><td>{{ $c->teachers_count }}</td><td>{{ $c->subjects_count }}</td><td><a href="{{ route('admin.classes.edit',$c) }}">تعديل</a> <form method="post" action="{{ route('admin.classes.destroy',$c) }}" style="display:inline">@csrf @method('delete')<button class="btn danger" type="submit" data-confirm="سيُنقل الصف الفارغ إلى المحذوفات مؤقتًا ويمكنك استعادته لاحقًا. هل تريد المتابعة؟">حذف</button></form></td></tr>@empty<tr><td colspan="7"><div class="empty">لا توجد صفوف حاليًا.</div></td></tr>@endforelse</tbody></table></div>{{ $classes->links() }}
@if($deletedClasses->isNotEmpty())
<section class="section-gap">
	<h2>الصفوف المحذوفة مؤخرًا</h2>
	<div class="table-wrap"><table><thead><tr><th>الصف</th><th>السنة</th><th>تاريخ الحذف</th><th>إجراء</th></tr></thead><tbody>
	@foreach($deletedClasses as $deletedClass)
		<tr><td>{{ $deletedClass->name }} {{ $deletedClass->section }}</td><td>{{ $deletedClass->academicYear?->name }}</td><td>{{ $deletedClass->deleted_at?->format('Y-m-d H:i') }}</td><td><form method="post" action="{{ route('admin.classes.restore', $deletedClass->id) }}">@csrf @method('patch')<button class="btn secondary" type="submit">تراجع / استعادة</button></form></td></tr>
	@endforeach
	</tbody></table></div>
</section>
@endif
@endsection
