<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\SchoolAccountService;
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

    public function card(Student $student)
    {
        return view('admin.accounts.card', ['student' => $student->load('user', 'classroom')]);
    }
}
