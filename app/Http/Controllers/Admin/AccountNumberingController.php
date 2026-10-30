<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\SchoolAccountService;
use App\Services\StudentCardPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AccountNumberingController extends Controller
{
    public function index(SchoolAccountService $accounts)
    {
        return view('admin.accounts.settings', ['settings' => $accounts->settings(), 'previewContext' => $accounts->context(), 'counts' => ['student' => Student::withTrashed()->count(), 'teacher' => Teacher::withTrashed()->count()]]);
    }

    private function data(Request $request, SchoolAccountService $accounts): array
    {
        $rules = ['school_name' => 'required|string|max:255', 'school_english_name' => 'required|string|max:80', 'school_code' => 'required|string|max:20', 'student_finance_visible' => 'required|boolean'];
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

    public function card(Student $student, StudentCardPdfService $pdf)
    {
        $student->load(['user', 'classroom', 'guardians.user']);
        $student->setAttribute('verification_url', URL::temporarySignedRoute('student.card.verify', now()->addYear(), ['student' => $student->id]));
        $data = ['student' => $student];
        if (request()->query('format') === 'pdf') {
            return $pdf->download($data, 'student-card-'.$student->student_number.'.pdf');
        }

        $pdf->preparePrintableCards(collect([$student]));

        return view(request()->query('format') === 'print' ? 'admin.accounts.card-print' : 'admin.accounts.card', $data);
    }

    public function cards(Request $request, StudentCardPdfService $pdf)
    {
        $selectedIds = null;
        if ($request->query('format') === 'pdf') {
            $validated = $request->validate([
                'student_ids' => ['required', 'array', 'min:1', 'max:10000'],
                'student_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            ]);
            $selectedIds = array_map('intval', $validated['student_ids']);
        }

        $query = Student::with(['user', 'classroom.academicYear', 'guardians.user'])
            ->when($request->integer('classroom_id'), fn ($q, $id) => $q->where('classroom_id', $id))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status));
        if ($selectedIds !== null) {
            $query->whereIn('id', $selectedIds);
        }
        $students = $query->orderBy('classroom_id')->orderBy('student_number')->get();

        abort_if($students->isEmpty(), 404, 'لا يوجد طلاب مطابقون للطباعة.');
        abort_if($selectedIds !== null && $students->count() !== count($selectedIds), 404);
        $expires = now()->addYear();
        $students->each(fn ($student) => $student->setAttribute('verification_url', URL::temporarySignedRoute('student.card.verify', $expires, ['student' => $student->id])));

        if ($selectedIds !== null) {
            return $pdf->downloadBulk($students, 'student-cards.pdf');
        }

        $pdf->preparePrintableCards($students);

        return view('admin.accounts.cards-print', compact('students'));
    }

    public function verify(Student $student)
    {
        $user = request()->user();
        abort_unless(config('nasbia.public_student_card_verification', false)
            || ($user?->isAdmin() && $user->status === 'active'), 404);
        $student->load(['user', 'classroom']);
        abort_unless($student->status === 'active' && $student->user?->status === 'active', 404);

        return response()->view('admin.accounts.verify', compact('student'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
