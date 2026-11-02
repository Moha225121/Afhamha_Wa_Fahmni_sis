@extends($teacherMode ? 'teacher.layout' : 'admin.layout')

@section('title', 'إرسال إشعار')
@section('subtitle', $teacherMode ? 'إشعار الطلاب أو أولياء أمور الصفوف المكلّف بها.' : 'إرسال إشعار إلى فئة من حسابات المدرسة.')

@section('content')
<section class="panel" style="max-width:760px">
    <form method="post" action="{{ $teacherMode ? route('teacher.notifications.store') : route('admin.notifications.store') }}" class="form-grid">
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
        <label>عنوان الإشعار<input name="title" value="{{ old('title') }}" maxlength="150" required></label>
        <label>نص الإشعار<textarea name="body" rows="5" maxlength="600" required>{{ old('body') }}</textarea></label>
        <label>المستلمون
            <select name="audience" required>
                @foreach($audiences as $audience)
                    <option value="{{ $audience }}" @selected(old('audience', $audiences[0]) === $audience)>{{ ['all' => 'جميع الحسابات', 'students' => 'الطلاب', 'parents' => 'أولياء الأمور', 'teachers' => 'المعلمون', 'administrators' => 'الإدارة'][$audience] }}</option>
                @endforeach
            </select>
        </label>
        <label>الصف الدراسي @unless($teacherMode)<span>(اختياري)</span>@endunless
            <select name="classroom_id" @required($teacherMode)>
                @unless($teacherMode)<option value="">كل الصفوف</option>@endunless
                @foreach($classrooms as $classroom)
                    <option value="{{ $classroom->id }}" @selected((string) old('classroom_id') === (string) $classroom->id)>{{ $classroom->name }}{{ $classroom->section ? ' — '.$classroom->section : '' }}</option>
                @endforeach
            </select>
        </label>
        <div><button class="btn primary" type="submit">إرسال الإشعار</button></div>
    </form>
    <p class="muted">سيُحفظ الإشعار داخل المنصة. سيصل تنبيه المتصفح إلى الحسابات التي فعّلت الإشعارات. عند اختيار صف، يشمل خيار جميع الحسابات الطلاب وأولياء أمورهم والمعلمين المكلفين بذلك الصف.</p>
</section>
@endsection
