<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
use App\Services\ResultPublicationService;
use App\Services\SchoolAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResultManagementController extends Controller
{
    public function index(Request $request)
    {
        $periods = DB::table('exam_periods as p')->join('academic_years as y', 'y.id', '=', 'p.academic_year_id')->when($request->academic_year_id, fn ($q, $id) => $q->where('p.academic_year_id', $id))->when($request->status, fn ($q, $status) => $q->where('p.status', $status))->select('p.*', 'y.name as academic_year')->orderByDesc('p.id')->paginate(20)->withQueryString();

        return view('admin.results.index', compact('periods') + ['years' => AcademicYear::all(), 'classrooms' => Classroom::all(), 'statuses' => ResultPublicationService::STATUSES]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'academic_year_id' => 'required|exists:academic_years,id', 'term' => 'required|string|max:80', 'exam_type' => 'required|string|max:80', 'grade' => 'nullable|string|max:255', 'classroom_id' => 'nullable|exists:classrooms,id', 'scheduled_at' => 'nullable|date']);
        if (! empty($data['scheduled_at'])) {
            $data['scheduled_at'] = Carbon::parse($data['scheduled_at'])->format('Y-m-d H:i:s');
        }
        if (! empty($data['classroom_id'])) {
            abort_unless(Classroom::whereKey($data['classroom_id'])->where('academic_year_id', $data['academic_year_id'])->exists(), 422, 'الشعبة لا تنتمي للسنة المختارة.');
        }
        $id = DB::transaction(function () use ($data) {
            $id = DB::table('exam_periods')->insertGetId($data + ['created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            app(SchoolAccountService::class)->audit('result_period_created', 'results', $id, [], $data);

            return $id;
        });

        return redirect()->route('admin.results.show', $id)->with('success', 'تم إنشاء الفترة كمسودة.');
    }

    public function show(int $period, ResultPublicationService $results)
    {
        $record = DB::table('exam_periods')->find($period);
        abort_unless($record, 404);
        $marks = DB::table('result_marks as m')->join('students as st', 'st.id', '=', 'm.student_id')->join('users as u', 'u.id', '=', 'st.user_id')->join('subjects as s', 's.id', '=', 'm.subject_id')->where('m.exam_period_id', $period)->select('m.*', 'u.name as student', 's.name as subject')->orderBy('u.name')->get();
        $exams = DB::table('exams as e')->join('classrooms as c', 'c.id', '=', 'e.classroom_id')->join('subjects as s', 's.id', '=', 'e.subject_id')->where('c.academic_year_id', $record->academic_year_id)->when($record->classroom_id, fn ($q) => $q->where('c.id', $record->classroom_id))->when($record->grade, fn ($q) => $q->where('c.name', $record->grade))->where(fn ($q) => $q->whereNull('e.exam_period_id')->orWhere('e.exam_period_id', $period))->select('e.*', 's.name as subject')->get();

        $sheets = DB::table('grade_sheets as g')->join('teachers as t', 't.id', '=', 'g.teacher_id')->join('users as u', 'u.id', '=', 't.user_id')->join('classrooms as c', 'c.id', '=', 'g.classroom_id')->where('c.academic_year_id', $record->academic_year_id)->when($record->classroom_id, fn ($q) => $q->where('c.id', $record->classroom_id))->when($record->grade, fn ($q) => $q->where('c.name', $record->grade))->select('g.id', 'u.name as teacher', 'c.name as classroom', 'c.section')->get();

        return view('admin.results.show', ['period' => $record, 'marks' => $marks, 'exams' => $exams, 'sheets' => $sheets, 'students' => $results->students($record)->get(), 'subjects' => Subject::all(), 'statuses' => ResultPublicationService::STATUSES]);
    }

    public function mark(Request $request, int $period, ResultPublicationService $results)
    {
        $data = $request->validate(['student_id' => 'required|integer|exists:students,id', 'subject_id' => 'required|integer|exists:subjects,id', 'maximum_score' => 'required|numeric|gt:0|max:100000|decimal:0,2', 'score' => 'required|numeric|min:0|lte:maximum_score|decimal:0,2', 'notes' => 'nullable|string|max:3000']);
        $results->saveMark($period, $data);

        return back()->with('success', 'تم حفظ درجة المادة.');
    }

    public function import(Request $request, int $period, ResultPublicationService $results)
    {
        $data = $request->validate(['exam_ids' => 'required|array|min:1', 'exam_ids.*' => 'required|integer|distinct|exists:exams,id']);
        $subjects = DB::table('exams')->whereIn('id', $data['exam_ids'])->get(['subject_id', 'classroom_id'])->map(fn ($e) => $e->classroom_id.':'.$e->subject_id);
        if ($subjects->unique()->count() !== $subjects->count()) {
            throw ValidationException::withMessages(['exam_ids' => 'اختر اختبارًا واحدًا لكل مادة في عملية الاستيراد.']);
        }
        $results->importExams($period, $data['exam_ids']);

        return back()->with('success', 'تم استيراد الدرجات كمسودة. راجعها ثم اعتمد الفترة.');
    }

    public function status(Request $request, int $period, ResultPublicationService $results)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(ResultPublicationService::STATUSES))]]);
        $results->transition($period, $data['status']);

        return back()->with('success', 'تم تحديث حالة النتائج إلى '.$data['status'].'.');
    }

    public function importSheet(Request $request, int $period, ResultPublicationService $results)
    {
        $data = $request->validate(['sheet_id' => 'required|integer|exists:grade_sheets,id', 'subject_id' => 'required|integer|exists:subjects,id']);
        $results->importSheet($period, $data['sheet_id'], $data['subject_id']);

        return back()->with('success', 'تم استيراد كشف المعلم إلى المسودة. راجع الدرجات قبل الاعتماد.');
    }
}
