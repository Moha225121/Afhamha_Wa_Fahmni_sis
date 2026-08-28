@extends('teacher.layout')

@section('title','سجل الدرجات')
@section('subtitle','إدارة درجات الصف حسب نوع النشاط')

@section('content')
@php
    $studentsPayload = $students->map(function ($student) {
        return [
            'id' => $student->id,
            'name' => $student->user?->name ?? 'طالب',
        ];
    })->values();
@endphp

<div class="grade-sheet-page">
    <div class="form-hint grade-instructions">
        يمكنك تسمية كل عمود حسب نظام الدرجات في المدرسة، وتحديد الدرجة القصوى الخاصة به. أدخل درجات الطلاب ضمن الحد المحدد، واحذف أي عمود غير مطلوب باستخدام زر الحذف.
    </div>
    <div class="grade-top-row">
        <div class="grade-headline">
            <span class="meta-label">الصف</span>
            <form method="get" action="{{ route('teacher.grades.index') }}" class="grade-filter-form">
                <select name="classroom_id" class="mini-select" onchange="this.form.submit()">
                    <option value="">اختر الصف</option>
                    @foreach($classrooms as $classroomOption)
                        <option value="{{ $classroomOption->id }}" @selected((string)($classroom?->id ?? '') === (string)$classroomOption->id)>
                            {{ $classroomOption->name }} {{ $classroomOption->section }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>

        <div class="grade-actions">
            <button type="button" class="btn secondary" id="add-grade-column">إضافة عمود</button>
            <button type="button" class="btn primary" id="save-grade-sheet">حفظ التغييرات</button>
        </div>
    </div>

    @if($classroom)
        <div class="grade-panel">
            <div class="grade-grid-wrap">
                <table class="grade-grid" id="grade-sheet-table">
                    <thead>
                        <tr id="grade-table-head-row">
                            <th class="student-col">الطالب</th>
                        </tr>
                    </thead>
                    <tbody id="grade-table-body"></tbody>
                </table>
            </div>
            <div id="save-status" class="save-status" aria-live="polite"></div>
        </div>
    @else
        <div class="grade-empty">اختر صفًا لعرض الطلاب المسجلين فقط.</div>
    @endif
</div>

<script>
    (function () {
        const classroomId = "{{ $classroom?->id ?? 'none' }}";
        const students = @json($studentsPayload);

        // Columns saved on server for this classroom (from GradeController)
        const serverColumns = @json($gradeSheetColumns ?? []);
        // Scores saved on server (overall percent), keyed by student id
        const serverScores = @json($grades->mapWithKeys(function ($g) { return [(string)$g->student_id => $g->score]; }) ?? []);
        const serverColumnScores = @json($columnScores ?? []);

        const defaultColumns = [
            { key: 'monthly', title: 'اختبار شهري', weight: 20, max_score: 100, grades: {} },
            { key: 'midterm', title: 'اختبار نصفي', weight: 20, max_score: 100, grades: {} },
            { key: 'work', title: 'أعمال', weight: 20, max_score: 100, grades: {} },
            { key: 'activity', title: 'نشاط', weight: 20, max_score: 100, grades: {} }
        ];

        const storageKey = 'teacherGradeSheet_' + classroomId;

        function hasVisibleColumns(columns) {
            return Array.isArray(columns) && columns.length > 0;
        }

        function readSavedColumns() {
            try {
                const raw = localStorage.getItem(storageKey);
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed.columns) && parsed.columns.length) {
                    return parsed.columns;
                }
            } catch (error) {
                console.warn('Unable to read saved sheet', error);
            }
            return null;
        }

        function saveState(columns) {
            localStorage.setItem(storageKey, JSON.stringify({ columns }));
        }

        function buildColumnState() {
            const saved = readSavedColumns();
            // priority: client localStorage (teacher temporary view) -> server saved sheet -> defaults
            if (saved && hasVisibleColumns(saved)) {
                return saved.map((column, index) => ({
                    key: column.key || 'column_' + index,
                    title: column.title || 'عمود جديد',
                    weight: Number(column.weight) > 0 ? Number(column.weight) : 20,
                    max_score: Number(column.max_score) > 0 ? Number(column.max_score) : 100,
                    grades: { ...(serverColumnScores[column.key] || {}), ...(column.grades || {}) }
                }));
            }

            if (Array.isArray(serverColumns) && serverColumns.length) {
                return serverColumns.map((column, index) => ({
                    key: column.key || 'column_' + index,
                    title: column.title || 'عمود جديد',
                    weight: Number(column.weight) > 0 ? Number(column.weight) : 20,
                    max_score: Number(column.max_score) > 0 ? Number(column.max_score) : 100,
                    grades: serverColumnScores[column.key] || {}
                }));
            }

            return defaultColumns.map((column, index) => ({
                key: column.key + '_' + index,
                title: column.title,
                weight: column.weight,
                max_score: column.max_score,
                grades: {}
            }));
        }

        const state = {
            columns: buildColumnState()
        };

        const headRow = document.getElementById('grade-table-head-row');
        const body = document.getElementById('grade-table-body');
        const saveStatus = document.getElementById('save-status');

        if (!headRow || !body) {
            return;
        }

        function calculateAverageForStudent(studentId) {
            let totalScore = 0;
            let totalWeight = 0;

            state.columns.forEach((column) => {
                const value = Number(column.grades[studentId]);
                const maximum = Number(column.max_score || 100);

                if (Number.isFinite(value) && maximum > 0) {
                    totalScore += (value / maximum) * 100;
                    totalWeight += 1;
                }
            });

            if (totalWeight === 0) {
                return null;
            }

            return Number((totalScore / totalWeight).toFixed(1));
        }

        function formatAverageForDisplay(value) {
            return value === null || value === undefined || value === '' ? '-' : value + '%';
        }

        function renderTable() {
            headRow.innerHTML = '<th class="student-col">الطالب</th><th class="avg-col">المتوسط</th>';
            state.columns.forEach((column, columnIndex) => {
                const th = document.createElement('th');
                th.className = 'assessment-col';
                th.innerHTML = `
                    <div class="column-header">
                        <input type="text" class="column-title-input" data-column-index="${columnIndex}" value="${escapeHtml(column.title)}" aria-label="اسم العمود">
                        <div class="column-tools">
                            <input type="number" class="column-max-input" data-column-index="${columnIndex}" min="1" max="100" value="${Number(column.max_score || 100)}" aria-label="الحد الأعلى للدرجة">
                            <button type="button" class="delete-column-btn" data-column-index="${columnIndex}" title="حذف العمود">×</button>
                        </div>
                    </div>
                `;
                headRow.appendChild(th);
            });

            body.innerHTML = '';

            students.forEach((student) => {
                const row = document.createElement('tr');
                row.dataset.studentId = student.id;

                const studentCell = document.createElement('td');
                studentCell.className = 'student-name';
                studentCell.textContent = student.name;
                row.appendChild(studentCell);

                const averageCell = document.createElement('td');
                averageCell.className = 'avg-cell';
                // If server has saved overall score, show it; otherwise compute from columns
                const serverVal = serverScores[String(student.id)];
                averageCell.textContent = formatAverageForDisplay(typeof serverVal !== 'undefined' && serverVal !== null ? Number(serverVal) : calculateAverageForStudent(student.id));
                row.appendChild(averageCell);

                state.columns.forEach((column, columnIndex) => {
                    const cell = document.createElement('td');
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0';
                    input.max = String(Number(column.max_score || 100));
                    input.step = '1';
                    input.value = column.grades[student.id] ?? '';
                    input.dataset.columnIndex = String(columnIndex);
                    input.dataset.studentId = String(student.id);
                    input.className = 'grade-input';
                    input.placeholder = '0';

                    input.addEventListener('input', function () {
                        const columnKey = Number(this.dataset.columnIndex);
                        const studentId = Number(this.dataset.studentId);
                        const nextValue = this.value === '' ? '' : Number(this.value);
                        state.columns[columnKey].grades[studentId] = nextValue;
                        row.querySelector('.avg-cell').textContent = formatAverageForDisplay(calculateAverageForStudent(studentId));
                    });

                    cell.appendChild(input);
                    row.appendChild(cell);
                });

                body.appendChild(row);
            });

            attachListeners();
        }

        function attachListeners() {
            document.querySelectorAll('.column-title-input').forEach((input) => {
                input.addEventListener('input', function () {
                    const index = Number(this.dataset.columnIndex);
                    state.columns[index].title = this.value || 'عمود جديد';
                });
            });

            document.querySelectorAll('.column-max-input').forEach((input) => {
                input.addEventListener('input', function () {
                    const index = Number(this.dataset.columnIndex);
                    const nextMax = Number(this.value) || 1;
                    state.columns[index].max_score = Math.min(100, Math.max(1, nextMax));
                    this.value = state.columns[index].max_score;
                    const columnInputs = document.querySelectorAll(`.grade-input[data-column-index="${index}"]`);
                    columnInputs.forEach((gradeInput) => { gradeInput.max = String(state.columns[index].max_score); });
                });
            });

            document.querySelectorAll('.delete-column-btn').forEach((button) => {
                button.addEventListener('click', function () {
                    const index = Number(this.dataset.columnIndex);
                    if (state.columns.length <= 1) {
                        saveStatus.textContent = 'يجب оставить عمود واحد على الأقل.';
                        saveStatus.classList.add('show');
                        setTimeout(() => saveStatus.classList.remove('show'), 2000);
                        return;
                    }
                    const columnTitle = state.columns[index]?.title || 'هذا العمود';
                    if (!window.confirm(`هل أنت متأكد من حذف ${columnTitle}؟ سيتم حذف درجاته من شاشة هذا الصف.`)) {
                        return;
                    }
                    state.columns.splice(index, 1);
                    renderTable();
                });
            });
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        document.getElementById('add-grade-column')?.addEventListener('click', function () {
            state.columns.push({
                key: 'custom_' + Date.now(),
                title: 'عمود جديد',
                weight: 20,
                max_score: 100,
                grades: {}
            });
            saveState(state.columns);
            renderTable();
        });

        document.getElementById('save-grade-sheet')?.addEventListener('click', function () {
            // persist sheet columns to localStorage and to server as sheet definition + computed scores
            saveState(state.columns);

            const payload = {
                classroom_id: Number(classroomId),
                sheet_columns: state.columns.map(c => ({ key: c.key, title: c.title, max_score: c.max_score })),
                scores: {},
                column_scores: {}
            };

            for (const column of state.columns) {
                for (const student of students) {
                    const value = column.grades[student.id];
                    if (value !== '' && value !== null && typeof value !== 'undefined' && Number(value) > Number(column.max_score || 100)) {
                        saveStatus.textContent = `${column.title} للطالب ${student.name} لا يمكن أن تتجاوز ${column.max_score}.`;
                        saveStatus.classList.add('show');
                        return;
                    }
                }
            }

            students.forEach(s => {
                const val = calculateAverageForStudent(s.id);
                if (val !== null && !isNaN(val)) {
                    payload.scores[s.id] = val;
                }
            });
            state.columns.forEach(column => {
                payload.column_scores[column.key] = {};
                students.forEach(student => {
                    const value = column.grades[student.id];
                    if (value !== '' && value !== null && typeof value !== 'undefined' && Number.isFinite(Number(value))) {
                        payload.column_scores[column.key][student.id] = Number(value);
                    }
                });
            });

            const token = '{{ csrf_token() }}';

            fetch('{{ route('teacher.grades.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(async (res) => {
                if (!res.ok) throw res;
                const json = await res.json();
                const saved = json?.scores || {};
                // update displayed averages with server-normalized values
                Object.keys(saved).forEach(id => {
                    const row = document.querySelector(`#grade-table-body tr[data-student-id="${id}"]`);
                    if (row) row.querySelector('.avg-cell').textContent = formatAverageForDisplay(saved[id]);
                });
                saveStatus.textContent = 'تم حفظ الدرجات بنجاح.';
                saveStatus.classList.add('show');
                setTimeout(() => saveStatus.classList.remove('show'), 3000);
            }).catch(async (err) => {
                let msg = 'فشل الحفظ.';
                try {
                    const json = await err.json();
                    const validationMessage = Object.values(json?.errors || {}).flat()[0];
                    if (validationMessage) msg = validationMessage;
                    else if (json?.message) msg = json.message;
                } catch(e) {}
                saveStatus.textContent = msg;
                saveStatus.classList.add('show');
                setTimeout(() => saveStatus.classList.remove('show'), 3000);
            });
        });

        renderTable();
    })();
</script>

<style>
    .grade-sheet-page {
        padding: 12px 18px 0;
    }

    .grade-top-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 18px;
        padding: 4px 0;
    }

    .grade-headline {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
        color: #334155;
    }

    .meta-label {
        color: #64748b;
    }

    .grade-filter-form {
        display: inline-block;
    }

    .grade-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .mini-select {
        padding: 8px 12px;
        border: 1px solid #dfe3eb;
        border-radius: 8px;
        background: #fff;
        font-size: 14px;
        color: #1f2937;
    }

    .grade-panel {
        background: transparent;
        border-radius: 18px;
        padding: 0;
    }

    .grade-grid-wrap {
        overflow-x: auto;
        overflow-y: hidden;
        border-radius: 12px;
        background: rgba(255,255,255,0.15);
        scrollbar-width: thin;
        scrollbar-color: #cfd9ec transparent;
        padding-bottom: 8px;
        -webkit-overflow-scrolling: touch;
    }

    .grade-grid-wrap::-webkit-scrollbar {
        height: 10px;
    }

    .grade-grid-wrap::-webkit-scrollbar-thumb {
        background: #cfd9ec;
        border-radius: 999px;
    }

    .grade-grid {
        width: 100%;
        min-width: 980px;
        border-collapse: collapse;
        background: transparent;
    }

    .grade-grid th,
    .grade-grid td {
        border: 1px solid #dfe3eb;
        padding: 10px 12px;
        text-align: center;
        background: rgba(255,255,255,0.08);
        vertical-align: middle;
    }

    .grade-grid th {
        background: #f7f8fa;
        color: #334155;
        font-weight: 700;
        font-size: 15px;
    }

    .student-col {
        min-width: 180px;
        text-align: right;
    }

    .assessment-col {
        min-width: 170px;
    }

    .column-header {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: center;
    }

    .column-title-input {
        width: 100%;
        min-width: 120px;
        border: 1px solid #dfe3eb;
        border-radius: 8px;
        padding: 8px 10px;
        background: #fff;
        text-align: center;
        font-size: 14px;
        color: #1f2937;
    }

    .column-tools {
        display: flex;
        align-items: center;
        gap: 6px;
        width: 100%;
        justify-content: center;
    }

    .column-weight-input {
        width: 76px;
        border: 1px solid #dfe3eb;
        border-radius: 8px;
        padding: 6px 8px;
        background: #fff;
        text-align: center;
        font-size: 13px;
        color: #1f2937;
    }

    .delete-column-btn {
        width: 28px;
        height: 28px;
        border: 1px solid #ef4444;
        border-radius: 50%;
        background: #fff;
        color: #ef4444;
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
    }

    .grade-input {
        width: 100%;
        min-width: 70px;
        height: 38px;
        border: 1px solid #dfe3eb;
        border-radius: 8px;
        background: #fff;
        text-align: center;
        font-size: 15px;
        color: #1f2937;
    }

    .student-name {
        text-align: right;
        min-width: 180px;
        font-weight: 600;
        color: #1f2937;
    }

    .avg-col {
        min-width: 100px;
        background: #f8fafc;
    }

    .avg-cell {
        font-weight: 700;
        color: #1f2937;
        background: #f8fafc;
    }

    .save-status {
        min-height: 24px;
        margin-top: 12px;
        color: #0f766e;
        font-weight: 700;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .save-status.show {
        opacity: 1;
    }

    .grade-empty {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 18px;
        color: #64748b;
        text-align: center;
    }
</style>
@endsection
