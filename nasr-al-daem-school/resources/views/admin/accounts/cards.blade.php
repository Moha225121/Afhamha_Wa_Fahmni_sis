<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>اختيار بطاقة طالب للطباعة</title>
    <style>
        :root{font-family:Tahoma,Arial,sans-serif;color:#17324b;background:#f3f7f8}
        *{box-sizing:border-box}body{margin:0;padding:clamp(16px,4vw,36px)}
        main{max-width:1100px;margin:auto}.heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}
        h1{font-size:clamp(22px,4vw,30px);margin:0 0 8px}.muted{color:#6c7d88;margin:0}
        .button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 16px;border:0;border-radius:10px;background:#087f83;color:#fff;text-decoration:none;font-weight:700;white-space:nowrap}
        .filters{display:grid;grid-template-columns:minmax(180px,1fr) minmax(170px,240px) minmax(140px,180px) auto;gap:10px;padding:16px;background:#fff;border:1px solid #d9e4e7;border-radius:16px;margin-bottom:16px}
        input,select{width:100%;min-height:42px;padding:9px 12px;border:1px solid #cbd9de;border-radius:9px;background:#fff;font:inherit}
        .list{overflow:hidden;background:#fff;border:1px solid #d9e4e7;border-radius:16px}.row{display:grid;grid-template-columns:minmax(180px,1fr) minmax(110px,170px) minmax(140px,200px) auto;align-items:center;gap:16px;padding:14px 18px;border-bottom:1px solid #e7edef}.row:last-child{border-bottom:0}.row-head{background:#f7fafb;color:#657984;font-size:13px;font-weight:bold}.name{font-weight:bold}.class{color:#647985}.empty{padding:32px;text-align:center;color:#647985}.class-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px;padding:12px 16px;background:#e7f4f3;border:1px solid #c5e5e2;border-radius:12px}.pagination{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:18px auto 0;color:#637781}.page-link,.page-current,.page-disabled{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 12px;border:1px solid #d4e0e3;border-radius:9px;background:#fff;color:#176c70;text-decoration:none;font-weight:700}.page-current{background:#087f83;border-color:#087f83;color:#fff}.page-disabled{color:#9aa9ae;background:#f7f9fa}.page-summary{margin:0 8px;font-size:13px}
        @media(max-width:700px){body{padding:14px}.heading{align-items:flex-start;flex-direction:column}.filters{grid-template-columns:1fr}.row{grid-template-columns:1fr auto;gap:7px;padding:14px}.row-head{display:none}.class,.number{font-size:13px}.row .button{grid-column:2;grid-row:1 / span 3}.class-actions{align-items:stretch;flex-direction:column}.class-actions .button{white-space:normal}.page-summary{order:3;width:100%;text-align:center;margin-top:3px}.page-link,.page-current,.page-disabled{min-width:36px;height:36px;padding:0 9px}}
    </style>
</head>
<body>
<main>
    <header class="heading">
        <div><h1>بطاقات الطلاب</h1><p class="muted">اختر الطالب المطلوب لعرض بطاقته وطباعتها أو حفظها PDF.</p></div>
        <a class="button" href="{{ route('admin.students.index') }}">العودة إلى الطلاب</a>
    </header>
    <form class="filters" method="get" action="{{ route('admin.students.cards') }}">
        <input name="q" value="{{ request('q') }}" placeholder="ابحث باسم الطالب أو رقمه الدراسي">
        <select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected(request('classroom_id')==$classroom->id)>{{ $classroom->name }}@if($classroom->section && $classroom->section !== 'أ') {{ $classroom->section }}@endif</option>@endforeach</select>
        <select name="status"><option value="">كل الحالات</option><option value="active" @selected(request('status')==='active')>نشط</option><option value="inactive" @selected(request('status')==='inactive')>غير نشط</option></select>
        <button class="button" type="submit">بحث</button>
    </form>
    @if(request()->integer('classroom_id') && $classrooms->contains('id',request()->integer('classroom_id')))
        @php($selectedClass = $classrooms->firstWhere('id',request()->integer('classroom_id')))
        <div class="class-actions"><span>يمكنك تجهيز بطاقات طلاب {{ $selectedClass->name }} معًا.</span><a class="button" href="{{ route('admin.students.cards.class',$selectedClass) }}{{ request('status') ? '?status='.urlencode(request('status')) : '' }}">عرض بطاقات الصف معًا</a></div>
    @endif
    <section class="list" aria-label="قائمة الطلاب">
        <div class="row row-head"><span>اسم الطالب</span><span>الرقم الدراسي</span><span>الصف</span><span>البطاقة</span></div>
        @forelse($students as $student)
            <article class="row">
                <span class="name">{{ $student->user->name }}</span>
                <span class="number" dir="ltr">{{ $student->student_number }}</span>
                <span class="class">{{ $student->classroom?->name ?? '—' }}@if($student->classroom?->section && $student->classroom->section !== 'أ') {{ $student->classroom->section }}@endif</span>
                <a class="button" href="{{ route('admin.students.account-card',$student) }}">عرض / طباعة</a>
            </article>
        @empty
            <div class="empty">لا توجد نتائج مطابقة لبحثك.</div>
        @endforelse
    </section>
    @if($students->lastPage() > 1)
        @php($startPage = max(1,$students->currentPage()-2))
        @php($endPage = min($students->lastPage(),$students->currentPage()+2))
        <nav class="pagination" aria-label="التنقل بين الصفحات">
            @if($students->previousPageUrl())<a class="page-link" href="{{ $students->previousPageUrl() }}" aria-label="الصفحة السابقة">السابق</a>@else<span class="page-disabled">السابق</span>@endif
            @if($startPage > 1)<a class="page-link" href="{{ $students->url(1) }}">1</a>@if($startPage > 2)<span>…</span>@endif @endif
            @for($page = $startPage; $page <= $endPage; $page++)@if($page === $students->currentPage())<span class="page-current" aria-current="page">{{ $page }}</span>@else<a class="page-link" href="{{ $students->url($page) }}">{{ $page }}</a>@endif @endfor
            @if($endPage < $students->lastPage())@if($endPage < $students->lastPage()-1)<span>…</span>@endif<a class="page-link" href="{{ $students->url($students->lastPage()) }}">{{ $students->lastPage() }}</a>@endif
            @if($students->nextPageUrl())<a class="page-link" href="{{ $students->nextPageUrl() }}" aria-label="الصفحة التالية">التالي</a>@else<span class="page-disabled">التالي</span>@endif
            <p class="page-summary">عرض {{ $students->firstItem() }}–{{ $students->lastItem() }} من {{ $students->total() }} طالبًا</p>
        </nav>
    @endif
</main>
</body>
</html>
