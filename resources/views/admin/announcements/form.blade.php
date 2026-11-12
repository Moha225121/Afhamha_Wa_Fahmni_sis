@extends('admin.layout')
@section('title', $announcement->exists ? 'تعديل الإعلان' : 'إنشاء إعلان')
@section('content')
<form class="form-card" method="post" action="{{ $announcement->exists ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}">
    @csrf
    <input type="hidden" name="schedule_timezone" value="{{ \App\Support\DisplayTimezone::name() }}">
    @if($announcement->exists) @method('put') @endif
    <div class="form-grid">
        <label class="wide">العنوان<input name="title" value="{{ old('title', $announcement->title) }}" required></label>
        <label class="wide">المحتوى<textarea rows="6" name="content" required>{{ old('content', $announcement->content) }}</textarea></label>
        <label>الجمهور
            <select name="audience">
                @foreach(['all' => 'الجميع', 'students' => 'الطلاب', 'teachers' => 'المعلمون', 'parents' => 'أولياء الأمور', 'supervisors' => 'المشرفون', 'staff' => 'الموظفون', 'classroom' => 'صف محدد'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('audience', $announcement->audience ?? 'all') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>الصف
            <select name="classroom_id">
                <option value="" @selected((string) old('classroom_id', $announcement->classroom_id) === '')>—</option>
                @foreach($classrooms as $classroom)
                    <option value="{{ $classroom->id }}" @selected((string) old('classroom_id', $announcement->classroom_id) === (string) $classroom->id)>{{ $classroom->name }}</option>
                @endforeach
            </select>
        </label>
        <label>تاريخ النشر ({{ \App\Support\DisplayTimezone::name() }})<input type="datetime-local" name="published_at" value="{{ old('published_at', $announcement->published_at?->copy()->timezone(\App\Support\DisplayTimezone::name())->format('Y-m-d\TH:i')) }}"><small>اتركه فارغًا للنشر الفوري عند اختيار «منشور».</small></label>
        <label>تاريخ الانتهاء ({{ \App\Support\DisplayTimezone::name() }})<input type="datetime-local" name="expires_at" value="{{ old('expires_at', $announcement->expires_at?->copy()->timezone(\App\Support\DisplayTimezone::name())->format('Y-m-d\TH:i')) }}"></label>
        <label>الحالة
            <select name="status">
                @foreach(['draft' => 'مسودة', 'published' => 'منشور', 'archived' => 'مؤرشف'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $announcement->status ?? 'draft') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>
    <div class="form-actions"><button class="btn primary">حفظ</button></div>
</form>
@endsection
