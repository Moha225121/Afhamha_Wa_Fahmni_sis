@extends('teacher.layout') @section('title','لوحة تحكم المعلم') @section('subtitle','ملخص يومي لصفوفك ومهامك التعليمية') @section('content')
<section class="teacher-welcome">
<div><span class="teacher-welcome__date">{{ now()->translatedFormat('l، j F Y') }}</span><h2>مرحبًا، {{ $teacher->user?->name ?? auth()->user()->name }}</h2><p>تابع حضور طلابك وأنجز مهامك التعليمية من مكان واحد.</p></div>
<a class="btn secondary" href="{{ route('teacher.lessons.create') }}">إنشاء درس جديد</a>
</section>
<section class="cards teacher-stats">
<article class="card stat teacher-stat teacher-stat--students"><span>الطلاب</span><strong>{{ $stats['students'] }}</strong><small>في جميع الصفوف</small></article>
<article class="card stat teacher-stat teacher-stat--attendance"><span>حضور اليوم</span><strong>{{ $stats['attendance_rate'] }}%</strong><small>{{ $stats['today_attendance'] }} سجل حضور</small></article>
<article class="card stat teacher-stat teacher-stat--tasks"><span>تحتاج متابعة</span><strong>{{ $stats['draft_exams'] }}</strong><small>اختبارات مسودة</small></article>
<article class="card stat teacher-stat teacher-stat--lessons"><span>إدارة الدروس</span><strong>{{ $stats['lessons'] }}</strong><small>دروس مجدولة</small></article>
</section>
<section class="teacher-dashboard-grid">
<div class="panel teacher-detail-panel"><div class="panel-heading"><div><span class="eyebrow">تحديث اليوم</span><h2>تفاصيل الحضور</h2></div><a href="{{ route('teacher.attendance.index') }}">فتح سجل الحضور</a></div>
@forelse($todayDetails as $detail)
<div class="teacher-detail-row"><span><strong>{{ $detail->student }}</strong><small>{{ $detail->classroom }}</small></span><span class="badge attendance-badge attendance-badge--{{ $detail->status }}">{{ ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'بعذر'][$detail->status] ?? $detail->status }}</span></div>
@empty <div class="empty">لم يتم تسجيل حضور اليوم بعد.</div>@endforelse
</div>
<div class="panel teacher-quick-panel"><div class="panel-heading"><div><span class="eyebrow">متابعة سريعة</span><h2>إجراءات مقترحة</h2></div></div><a class="quick-action" href="{{ route('teacher.attendance.index') }}"><strong>سجل حضور الصف</strong><small>حدّث حالات الطلاب اليوم</small><b>←</b></a><a class="quick-action" href="{{ route('teacher.exams.index') }}"><strong>راجع الاختبارات</strong><small>{{ $stats['draft_exams'] }} اختبارات بانتظار الإكمال</small><b>←</b></a><a class="quick-action" href="{{ route('teacher.assignments.index') }}"><strong>تابع الواجبات</strong><small>راجع التسليمات القادمة</small><b>←</b></a></div>
</section>
<section class="grid-2 teacher-upcoming-grid">
<div class="panel"><div class="panel-heading"><h2>الاختبارات القادمة</h2><a href="{{ route('teacher.exams.index') }}">عرض الكل</a></div>
@forelse($upcomingExams as $e)
<div class="activity">{{ $e->title }} — {{ $e->subject }} · {{ $e->classroom }}<small>{{ \Illuminate\Support\Carbon::parse($e->starts_at)->format('Y-m-d H:i') }}</small></div>
@empty
<div class="empty">لا توجد اختبارات قادمة.</div>
@endforelse
</div>
<div class="panel"><div class="panel-heading"><h2>واجبات قادمة</h2><a href="{{ route('teacher.assignments.index') }}">عرض الكل</a></div>
@forelse($upcomingAssignments as $a)
<div class="activity">{{ $a->title }} — {{ $a->subject }} · {{ $a->classroom }}<small>موعد التسليم: {{ $a->due_date }}</small></div>
@empty
<div class="empty">لا توجد واجبات قادمة.</div>
@endforelse
</div>
</section>
@endsection
