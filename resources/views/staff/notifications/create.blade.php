@extends('staff.layout')

@section('title', 'إرسال إشعار لولي الأمر')
@section('subtitle', 'ابحث عن الطالب ثم اختر ولي الأمر المرتبط به لإرسال الرسالة.')

@section('content')
    <div class="panel">
        <form method="post" action="{{ route('staff.notifications.store') }}" class="staff-form" id="staff-notification-form">
            @csrf

            <div class="staff-fields">
                <label>
                    الطالب
                    <select name="student_id" id="notification-student" data-searchable required>
                        <option value="">اختر الطالب</option>
                        @foreach ($students as $student)
                            <option value="{{ $student->id }}">{{ $student->user->name }} — {{ $student->classroom?->name }}</option>
                        @endforeach
                    </select>
                    <small class="muted">يمكنك كتابة اسم الطالب أو الصف للبحث ثم اختيار النتيجة.</small>
                </label>

                <label>
                    ولي الأمر
                    <select name="guardian_id" id="notification-guardian" data-searchable required disabled>
                        <option value="">اختر الطالب أولاً</option>
                        @foreach ($students as $student)
                            @foreach ($student->guardians as $guardian)
                                <option value="{{ $guardian->id }}" data-student-id="{{ $student->id }}">
                                    {{ $guardian->user->name }} — {{ $guardian->relationship ?: 'ولي أمر' }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                    <small class="muted">ستظهر أولياء الأمور المرتبطون بالطالب المختار فقط.</small>
                </label>
            </div>

            <label>
                العنوان
                <input name="title" required maxlength="255" placeholder="عنوان الإشعار">
            </label>

            <label>
                الرسالة
                <textarea name="message" required maxlength="5000" rows="6" placeholder="اكتب نص الإشعار"></textarea>
            </label>

            <div class="form-actions">
                <button class="btn primary" type="submit">إرسال الإشعار</button>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const student = document.getElementById('notification-student');
            const guardian = document.getElementById('notification-guardian');
            if (!student || !guardian) return;

            const filterGuardians = () => {
                const studentId = student.value;
                guardian.value = '';
                guardian.disabled = !studentId;

                Array.from(guardian.options).forEach((option, index) => {
                    if (index === 0) {
                        option.hidden = false;
                        option.disabled = false;
                        option.textContent = studentId ? 'اختر ولي الأمر' : 'اختر الطالب أولاً';
                        return;
                    }

                    const visible = Boolean(studentId) && option.dataset.studentId === studentId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                });

                guardian.dispatchEvent(new Event('change', { bubbles: true }));
            };

            student.addEventListener('change', filterGuardians);
            filterGuardians();
        })();
    </script>
@endpush
