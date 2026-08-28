@extends('teacher.layout')
@section('title', 'الدروس')
@section('subtitle', 'إدارة المحتوى التعليمي المنشور')
@section('actions')<a class="btn primary" href="{{ route('teacher.lessons.create') }}">إضافة درس جديد</a>@endsection
@section('content')
<div class="table-wrap"><table><thead><tr><th>العنوان</th><th>الوحدة</th><th>المادة</th><th>الصف</th><th>الحالة</th><th>تاريخ النشر</th><th>المرفقات</th><th></th></tr></thead><tbody>
@forelse($lessons as $lesson)
<tr><td>{{ $lesson->title }}</td><td>{{ $lesson->unit?->title ?: 'درس مستقل' }}</td><td>{{ $lesson->subject?->name }}</td><td>{{ $lesson->classroom?->name ?: '-' }}</td><td><span class="badge {{ $lesson->status === 'published' ? 'active' : 'inactive' }}">{{ $lesson->status === 'published' ? ($lesson->published_at?->isFuture() && ! $lesson->published_at?->isToday() ? 'مجدول' : 'منشور') : 'مسودة' }}</span></td><td>{{ $lesson->published_at?->format('Y-m-d H:i') ?: '-' }}</td><td>{{ $lesson->attachments->count() }}</td><td><a href="{{ route('teacher.lessons.edit', $lesson) }}">{{ $lesson->status === 'draft' ? 'متابعة' : 'تحديث الدرس' }}</a> · <form method="post" action="{{ route('teacher.lessons.destroy', $lesson) }}" style="display:inline">@csrf @method('delete')<button class="teacher-delete-action" type="submit" data-confirm="هل أنت متأكد من حذف الدرس؟">حذف</button></form></td></tr>
@empty <tr><td colspan="8">لا توجد دروس بعد.</td></tr>@endforelse
</tbody></table></div>{{ $lessons->links() }}
@endsection
