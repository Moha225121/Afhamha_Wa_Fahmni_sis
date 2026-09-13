@extends($audience.'.layout')
@section('title', 'تحليل مستوى الطالب')
@section('content')
<section class="panel" style="padding:24px;max-width:960px;margin:auto" dir="rtl">
    <a href="{{ route($audience === 'parent' ? 'parent.children.show' : $audience.'.students.show', $student) }}">العودة إلى ملف الطالب</a>
    <h1>تحليل مستوى {{ $student->user->name }}</h1>
    <p>{{ $audience === 'parent' ? 'فهم مستوى ابنك وخطوات عملية لدعمه في المنزل.' : 'مؤشرات الأداء وخطة مقترحة لدعم الطالب.' }}</p>
    <p>الفترة: {{ $snapshot['from'] }} — {{ $snapshot['to'] }} · {{ $snapshot['scope'] }}</p>
    <div style="display:flex;flex-wrap:wrap;gap:24px;margin:24px 0">
        <div><strong>{{ $snapshot['average_percent'] !== null ? $snapshot['average_percent'].'%' : 'غير متاح' }}</strong><br>متوسط نسب النتائج المنشورة ({{ $snapshot['results_count'] }})</div>
        <div><strong>{{ $snapshot['attendance_percent'] !== null ? $snapshot['attendance_percent'].'%' : 'غير متاح' }}</strong><br>الحضور من {{ $snapshot['attendance_total'] }} يوم مسجل، ويشمل التأخير</div>
        <div><strong>{{ $snapshot['assignments_submitted'] }} / {{ $snapshot['assignments_total'] }}</strong><br>واجبات مسلمة من الواجبات المنتهية</div>
    </div>
    <p>يعتمد التحليل على آخر 90 يومًا وبحد أقصى 100 نتيجة منشورة. المتوسط المعروض ليس المعدل الرسمي، والأيام غير المسجلة لا تُحسب غيابًا.</p>
    @if($error)<p role="alert" style="color:#a22">{{ $error }}</p>@endif
    @if($report)
        <p>أُعد التحليل في {{ $report['generated_at'] }}</p>
        <div style="white-space:pre-wrap;overflow-wrap:anywhere;line-height:2">{{ $report['text'] }}</div>
        <p>هذا تحليل مساعد بالذكاء الاصطناعي يُراجع مع المعلم. يتجدد عند تغير البيانات أو بعد انتهاء مدة حفظه المؤقتة (6 ساعات).</p>
    @elseif(!$hasEvidence)
        <p role="status">لا توجد بيانات كافية خلال هذه الفترة. سيصبح التحليل متاحًا بعد تسجيل الحضور أو الواجبات أو نشر النتائج.</p>
    @else
        <form method="post" action="{{ route($audience.'.students.analysis.generate', $student) }}" id="analysis-form">
            @csrf
            <button type="submit" class="btn primary" style="padding:12px 22px;background:#087f8c;color:white;border:0;border-radius:8px;cursor:pointer">إنشاء التحليل وخطة الدعم</button>
            <p id="analysis-progress" role="status" hidden>جارٍ تحليل البيانات وإعداد الخطة، قد يستغرق ذلك نحو دقيقة…</p>
        </form>
        <script>document.getElementById('analysis-form').addEventListener('submit', function () { this.querySelector('button').disabled = true; document.getElementById('analysis-progress').hidden = false; });</script>
    @endif
</section>
@endsection
