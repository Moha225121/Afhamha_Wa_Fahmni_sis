@extends('admin.layout')

@section('title', 'تشعبات الصف الأول والثاني')

@section('actions')
    <a class="btn secondary" href="{{ route('admin.classes.index') }}">العودة إلى الصفوف</a>
@endsection

@section('content')
    <section class="panel" dir="rtl">
        <h2>إضافة شُعب للصف الأول أو الثاني</h2>
        <p class="muted">اختر صفًا موجودًا، ثم أضف له الشُعب A أو B أو C. تُنسخ المرحلة والسنة الدراسية والمواد من الصف المختار، ولا يُنقل أي طالب تلقائيًا. الشُعب الموجودة مسبقًا لن تتكرر.</p>

        <form method="post" action="{{ route('admin.classes.sections.store') }}" class="form-card">
            @csrf
            <div class="form-grid">
                <label class="wide">الصف والسنة الدراسية
                    <select name="source_classroom_id" required>
                        <option value="">اختر الصف الأساسي</option>
                        @foreach($classrooms as $classroom)
                            <option value="{{ $classroom->id }}" @selected(old('source_classroom_id') == $classroom->id)>
                                {{ $classroom->display_grade }} — {{ $classroom->display_section }} — {{ $classroom->academicYear?->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <fieldset class="wide" style="border:1px solid var(--border,#d8e2ea);border-radius:12px;padding:1rem">
                    <legend>الشُعب المطلوب إضافتها</legend>
                    @foreach(['A', 'B', 'C'] as $section)
                        <label style="display:inline-flex;align-items:center;gap:.5rem;margin:0 0 0 1.25rem">
                            <input type="checkbox" name="sections[]" value="{{ $section }}" @checked(in_array($section, old('sections', []), true))>
                            الشعبة {{ $section }}
                        </label>
                    @endforeach
                </fieldset>
            </div>
            @error('source_classroom_id')<p class="form-error">{{ $message }}</p>@enderror
            @error('sections')<p class="form-error">اختر شعبة واحدة على الأقل.</p>@enderror
            <div class="form-actions"><button class="btn primary" type="submit">إنشاء الشعب المحددة</button></div>
        </form>
    </section>

    <section class="panel" dir="rtl" style="margin-top:1rem">
        <h2>الشُعب المسجلة حاليًا</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>الصف</th><th>الشعبة</th><th>السنة الدراسية</th><th>عدد الطلاب</th></tr></thead>
            <tbody>
                @forelse($classrooms as $classroom)
                    <tr><td>{{ $classroom->display_grade }}</td><td>{{ $classroom->display_section }}</td><td>{{ $classroom->academicYear?->name }}</td><td>{{ $classroom->students()->count() }}</td></tr>
                @empty
                    <tr><td colspan="4"><div class="empty">لا توجد صفوف أول أو ثانٍ مسجلة حتى الآن.</div></td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
@endsection
