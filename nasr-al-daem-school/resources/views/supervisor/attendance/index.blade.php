@extends('supervisor.layout')
@section('title', 'حضور اليوم')
@section('content')
<link rel="stylesheet" href="{{ asset('css/supervisor-attendance.css') }}?v=20261012-absence-qr">

<section class="attendance-page" data-attendance-page>
    <header class="attendance-intro">
        <div>
            <p class="attendance-kicker">إدارة الحضور اليومي</p>
            <h2>متابعة واضحة، وتسجيل أسرع</h2>
            <p>علامة الصح تعني غائب، والمربع الفارغ يعني حاضر. راجع الكشف ثم احفظ السجل.</p>
        </div>
        <div class="attendance-date-badge"><span>تاريخ السجل</span><strong>{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('l، j F Y') }}</strong></div>
    </header>

    <form class="attendance-filters" method="get" aria-label="اختيار الفصل والتاريخ">
        <label><span>الفصل</span><select name="classroom_id" required>@foreach($classes as $classroom)<option value="{{ $classroom->id }}" @selected((int) $classId === (int) $classroom->id)>{{ $classroom->name }}{{ $classroom->section ? ' — '.$classroom->section : '' }}</option>@endforeach</select></label>
        <label><span>التاريخ</span><input type="date" name="date" value="{{ $date }}" required></label>
        <button class="attendance-button attendance-button--primary" type="submit">عرض السجل</button>
        @if($classId)<a class="attendance-history-link" href="{{ route('supervisor.attendance.history', ['date_from' => $date, 'date_to' => $date, 'status' => 'absent']) }}">سجل الغياب لهذا اليوم</a>@endif
    </form>

    @if($classId)
        <section class="attendance-scan-card" data-quick-scan aria-labelledby="attendance-scan-title">
            <div class="attendance-scan-icon" aria-hidden="true">⌗</div>
            <div class="attendance-scan-copy"><p class="attendance-kicker">مسح بطاقة الطالب</p><h2 id="attendance-scan-title">تأكيد الحضور باستخدام QR</h2><p>المسح يزيل علامة الغياب. تُحفظ النتيجة مع الكشف عند الضغط على «حفظ حضور اليوم».</p></div>
            <div class="attendance-scan-controls"><label class="sr-only" for="attendance-scan-input">رابط البطاقة أو الرقم الدراسي</label><input id="attendance-scan-input" type="search" data-scan-input placeholder="رابط QR أو الرقم الدراسي" autocomplete="off"><button type="button" class="attendance-button attendance-button--primary" data-scan-submit>تحديد حاضر</button></div>
            <div class="attendance-camera-actions"><button type="button" class="attendance-button attendance-button--soft" data-camera-start aria-controls="attendance-camera" aria-expanded="false">مسح QR بالكاميرا</button><button type="button" class="attendance-button attendance-button--soft" data-camera-stop hidden>إيقاف الكاميرا</button><label class="attendance-button attendance-button--soft attendance-qr-upload">قراءة صورة QR<input type="file" accept="image/*" data-qr-image></label></div>
            <div id="attendance-camera" class="attendance-camera" data-camera-panel hidden><video data-camera-video playsinline muted aria-label="معاينة كاميرا QR"></video><p>وجّه الكاميرا إلى رمز QR في بطاقة الطالب.</p></div>
            <p class="attendance-scan-result" data-scan-result role="status" aria-live="polite"></p>
        </section>

        @php
            $summary = ['present' => 0, 'absent' => 0];
            foreach ($students as $student) {
                $status = old("records.{$student->id}.status", $records->get($student->id)?->status?->value ?? 'present');
                if (in_array($status, ['absent', 'excused_absence'], true)) $summary['absent']++;
                else $summary['present']++;
            }
        @endphp

        <div class="attendance-summary" aria-label="ملخص الحضور">
            <button class="attendance-stat attendance-stat--all is-active" type="button" data-status-filter="all" aria-pressed="true"><span>إجمالي الطلاب</span><strong data-summary-total>{{ $students->count() }}</strong></button>
            <button class="attendance-stat attendance-stat--present" type="button" data-status-filter="present" aria-pressed="false"><span>حاضر</span><strong data-summary-present>{{ $summary['present'] }}</strong></button>
            <button class="attendance-stat attendance-stat--absent" type="button" data-status-filter="absent" aria-pressed="false"><span>غائب</span><strong data-summary-absent>{{ $summary['absent'] }}</strong></button>
        </div>

        <form method="post" action="{{ route('supervisor.attendance.store') }}" enctype="multipart/form-data" data-attendance-form>
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <input type="hidden" name="classroom_id" value="{{ $classId }}">

            <section class="attendance-roster" aria-labelledby="attendance-roster-title">
                <div class="attendance-roster-head">
                    <div><p class="attendance-kicker">كشف الفصل</p><h2 id="attendance-roster-title">{{ $classes->firstWhere('id', $classId)?->name ?? 'قائمة الطلاب' }} {{ $classes->firstWhere('id', $classId)?->section }}</h2><p><span data-visible-count>{{ $students->count() }}</span> طالبًا في القائمة</p></div>
                    <label class="attendance-search"><span class="sr-only">البحث في الطلاب</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg><input type="search" data-student-search placeholder="ابحث بالاسم أو الرقم الدراسي" autocomplete="off"><button type="button" data-clear-search aria-label="مسح البحث" hidden>×</button></label>
                </div>

                <div class="attendance-tools">
                    <label class="attendance-select-visible"><input type="checkbox" data-select-visible><span>تحديد الطلاب الظاهرين كغائبين</span></label>
                    <div class="attendance-tool-actions">
                        <button class="attendance-button attendance-button--absent" type="button" data-mark-all-absent {{ $students->isEmpty() ? 'disabled' : '' }}>تحديد الفصل كاملًا كغائب</button>
                        <button class="attendance-button attendance-button--soft" type="button" data-undo-mark-all-absent hidden>تراجع عن تحديد الكل كغائب</button>
                    </div>
                </div>

                <div class="attendance-student-list" data-student-list>
                    @forelse($students as $student)
                        @php
                            $record = $records->get($student->id);
                            $storedStatus = old("records.{$student->id}.status", $record?->status?->value ?? 'present');
                            $status = in_array($storedStatus, ['absent', 'excused_absence'], true) ? 'absent' : 'present';
                            $searchText = trim(($student->user?->name ?? '').' '.$student->student_number);
                        @endphp
                        <article class="attendance-student" data-student-row data-student-id="{{ $student->id }}" data-student-number="{{ $student->student_number }}" data-status="{{ $status }}" data-search="{{ $searchText }}">
                            @php $isPresent = !in_array($status, ['absent', 'excused_absence'], true); @endphp
                            <input type="hidden" name="records[{{ $student->id }}][status]" value="{{ $status }}" data-status-value>
                            <header class="attendance-student-head">
                                <label class="attendance-student-check"><input type="checkbox" value="{{ $student->id }}" data-student-check @checked(!$isPresent) aria-label="حالة غياب {{ $student->user?->name }}"><span data-presence-label>{{ $isPresent ? 'حاضر' : 'غائب' }}</span></label>
                                <span class="attendance-student-avatar" aria-hidden="true">{{ mb_substr($student->user?->name ?? 'ط', 0, 1) }}</span>
                                <div class="attendance-student-identity"><a href="{{ route('supervisor.students.show', $student) }}">{{ $student->user?->name ?? 'طالب بدون اسم' }}</a><span>الرقم الدراسي: <b dir="auto">{{ $student->student_number ?: '—' }}</b></span></div>
                            </header>
                            <div class="attendance-student-details">
                                <label class="attendance-notes-field"><span>ملاحظة</span><input name="records[{{ $student->id }}][notes]" value="{{ old('records.'.$student->id.'.notes', $record?->notes) }}" maxlength="2000" placeholder="ملاحظة اختيارية"></label>
                            </div>
                        </article>
                    @empty
                        <div class="attendance-empty"><span aria-hidden="true">✓</span><strong>لا يوجد طلاب نشطون في هذا الفصل</strong><p>جرّب فصلًا آخر أو راجع الإدارة.</p></div>
                    @endforelse
                    <div class="attendance-empty" data-no-results hidden><span aria-hidden="true">⌕</span><strong>لا توجد نتائج مطابقة</strong><p>جرّب الاسم أو جزءًا من الرقم الدراسي.</p></div>
                </div>

                @if($students->isNotEmpty())
                    <footer class="attendance-save-bar"><p>التعديلات لا تُسجّل حتى تضغط حفظ.</p><button class="attendance-button attendance-button--primary" type="submit">حفظ حضور اليوم</button></footer>
                @endif
            </section>
        </form>
    @else
        <div class="attendance-empty"><span aria-hidden="true">✓</span><strong>لا توجد فصول مكلّف بها</strong><p>يرجى مراجعة الإدارة لتحديد فصل للمشرف.</p></div>
    @endif
</section>

@push('scripts')
    <script src="{{ asset('js/vendor/jsQR-1.4.0.js') }}" defer></script>
    <script src="{{ asset('js/supervisor-attendance.js') }}?v=20261012-absence-qr" defer></script>
@endpush
@endsection
