<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\SchoolAccountService;
use App\Services\StudentCardPdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountNumberingController extends Controller
{
    public function index(SchoolAccountService $accounts)
    {
        return view('admin.accounts.settings', ['settings' => $accounts->settings(), 'previewContext' => $accounts->context(), 'counts' => ['student' => Student::withTrashed()->count(), 'teacher' => Teacher::withTrashed()->count()]]);
    }

    private function data(Request $request, SchoolAccountService $accounts): array
    {
        $rules = ['school_name' => 'required|string|max:255', 'school_english_name' => 'required|string|max:80', 'school_code' => 'required|string|max:20'];
        foreach (['student', 'teacher'] as $role) {
            $rules += [$role.'_number_pattern' => 'required|string|max:80', $role.'_start' => 'required|integer|min:1|max:999999999', $role.'_digits' => 'required|integer|min:1|max:12', $role.'_reset_yearly' => 'required|boolean', $role.'_email_pattern' => 'required|string|max:200', $role.'_auto_email' => 'required|boolean'];
        }
        $settings = $request->validate($rules);
        $options = [];
        $old = $accounts->settings();
        foreach (['student', 'teacher'] as $role) {
            if (! preg_match('/\{(SEQ|0+1)\}/', $settings[$role.'_number_pattern'])) {
                throw ValidationException::withMessages([$role.'_number_pattern' => 'يجب أن يحتوي النمط على {SEQ} أو {0001} لمنع التكرار.']);
            }
            $accounts->number($settings, $role, (int) $settings[$role.'_start'], $accounts->context());
            $accounts->email($settings, $role, '20260015', ['first_name_en' => 'Mohamed', 'last_name_en' => 'Ahmed'], $accounts->context());
            $options['renumber_'.$role] = $request->boolean('renumber_'.$role) || $old[$role.'_number_pattern'] !== $settings[$role.'_number_pattern'];
            $options['update_'.$role.'_emails'] = $request->boolean('update_'.$role.'_emails');
        }

        return [$settings, $options];
    }

    public function preview(Request $request, SchoolAccountService $accounts)
    {
        [$settings, $options] = $this->data($request, $accounts);
        $plan = $accounts->preview($settings, $options);
        $request->session()->put('account_numbering_preview', ['settings' => $settings, 'options' => $options, 'revision' => $plan['revision']]);

        return view('admin.accounts.preview', compact('settings', 'options', 'plan'));
    }

    public function update(Request $request, SchoolAccountService $accounts)
    {
        $request->validate(['revision' => 'required|string|size:64']);
        $preview = $request->session()->get('account_numbering_preview');
        abort_unless($preview && hash_equals($preview['revision'], $request->input('revision')), 422, 'أجرِ المعاينة أولًا.');
        $accounts->save($preview['settings'], $preview['options'], $preview['revision']);
        $request->session()->forget('account_numbering_preview');

        return redirect()->route('admin.accounts.index')->with('success', 'تم حفظ الإعدادات وتنفيذ التغييرات المراجعة بأمان.');
    }

    public function card(Student $student)
    {
        $student->load(['user', 'classroom', 'guardians.user']);
        $student->setAttribute('verification_url', URL::temporarySignedRoute('student.card.verify', now()->addYear(), ['student' => $student->id]));
        $data = ['student' => $student];
        $pdf = app(StudentCardPdfService::class);

        if (request()->query('format') === 'pdf') {
            return $pdf->download('admin.accounts.card', $data, 'student-card-'.$student->student_number.'.pdf');
        }

        $student->setAttribute('qr_image', $pdf->qrImage($student->verification_url));

        return view('admin.accounts.card', $data);
    }

    public function cards(Request $request)
    {
        $students = Student::with(['user', 'classroom', 'guardians.user'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = trim((string) $request->input('q'));
                $query->where(function ($query) use ($search) {
                    $query->whereHas('user', fn ($user) => $user->where('name', 'like', '%'.$search.'%'))
                        ->orWhere('student_number', 'like', '%'.$search.'%');
                });
            })
            ->when($request->integer('classroom_id'), fn ($q, $id) => $q->where('classroom_id', $id))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('classroom_id')->orderBy('student_number')->paginate(30)->withQueryString();

        return view('admin.accounts.cards', [
            'students' => $students,
            'classrooms' => $this->orderedClassrooms(),
        ]);
    }

    public function printClass(Request $request, Classroom $classroom)
    {
        $classroom->load('academicYear');
        $students = Student::with('user')
            ->where('classroom_id', $classroom->id)
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('student_number')
            ->get();

        abort_if($students->isEmpty(), 404, 'لا يوجد طلاب في هذا الصف.');

        $students->each(fn ($student) => $student->setRelation('classroom', $classroom));

        $classrooms = $this->orderedClassrooms($classroom->academic_year_id);

        return view('admin.accounts.class-cards', compact('classroom', 'classrooms', 'students'));
    }

    public function downloadClassPdf(Request $request, Classroom $classroom, StudentCardPdfService $cards)
    {
        set_time_limit(120);

        $students = Student::with(['user', 'classroom.academicYear', 'guardians.user'])
            ->where('classroom_id', $classroom->id)
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('student_number')
            ->get();

        abort_if($students->isEmpty(), 404, 'لا يوجد طلاب في هذا الصف.');

        $expires = now()->addYear();
        $students->each(fn ($student) => $student->setAttribute(
            'verification_url',
            URL::temporarySignedRoute('student.card.verify', $expires, ['student' => $student->id]),
        ));

        return $cards->downloadBulk($students, 'student-cards-class-'.$classroom->id.'.pdf');
    }

    public function classCardQr(Student $student, StudentCardPdfService $cards)
    {
        $verificationUrl = URL::temporarySignedRoute('student.card.verify', now()->addYear(), ['student' => $student->id]);

        return response($cards->qrPng($verificationUrl), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function orderedClassrooms(?int $academicYearId = null)
    {
        $gradeOrder = [
            'الصف الأول' => 1,
            'الصف الثاني' => 2,
            'الصف الثالث' => 3,
            'الصف الرابع' => 4,
            'الصف الخامس' => 5,
            'الصف السادس' => 6,
            'الصف السابع' => 7,
            'الصف الثامن' => 8,
            'الصف التاسع' => 9,
            'الأول ثانوي' => 10,
            'الثاني ثانوي' => 11,
            'الثالث ثانوي' => 12,
        ];

        return Classroom::query()
            ->when($academicYearId !== null, fn ($query) => $query->where('academic_year_id', $academicYearId))
            ->get()
            ->sortBy(fn (Classroom $room) => sprintf(
                '%02d-%02d-%s-%s',
                $gradeOrder[$room->name] ?? 99,
                match ($room->section) {
                    'علمي' => 1,
                    'أدبي' => 2,
                    default => 0,
                },
                $room->name,
                $room->section ?? '',
            ))
            ->values();
    }

    public function verify(Student $student)
    {
        return view('admin.accounts.verify', ['student' => $student->load(['user', 'classroom', 'guardians.user'])]);
    }
}
