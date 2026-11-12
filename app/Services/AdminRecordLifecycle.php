<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Protect historical references, including relations hidden by soft-delete scopes. */
class AdminRecordLifecycle
{
    public function references(string $target, int $id, array $ignored = []): array
    {
        $references = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, $ignored, true)) continue;
            foreach (Schema::getForeignKeys($name) as $foreign) {
                if ($foreign['foreign_table'] !== $target || count($foreign['columns']) !== 1 || $foreign['foreign_columns'] !== ['id']) continue;
                if (DB::table($name)->where($foreign['columns'][0], $id)->exists()) $references[] = $name;
            }
        }

        return array_values(array_unique($references));
    }

    public function guard(string $target, int $id, string $field, array $ignored = []): void
    {
        $labels = [
            'classroom_subject' => 'ربط الصفوف', 'teacher_assignments' => 'تكليفات المعلمين',
            'schedules' => 'الجداول', 'exams' => 'الاختبارات', 'grades' => 'الدرجات',
            'assignments' => 'الواجبات', 'assignment_submissions' => 'تصحيح الواجبات',
            'lessons' => 'الدروس', 'units' => 'الوحدات', 'grade_sheets' => 'كشوف الدرجات',
            'result_marks' => 'النتائج', 'library_resources' => 'المكتبة',
            'students' => 'ملف طالب محفوظ', 'teachers' => 'ملف معلم محفوظ', 'guardians' => 'ملف ولي أمر محفوظ',
            'supervisor_class_assignments' => 'تكليفات الإشراف', 'student_notes' => 'ملاحظات الطلاب',
            'guardian_calls' => 'استدعاءات أولياء الأمور', 'attendance_records' => 'الحضور',
            'conversation_participants' => 'مشاركة في محادثات', 'conversations' => 'محادثات', 'messages' => 'رسائل',
            'fee_templates' => 'تعريفات رسوم', 'student_payments' => 'مدفوعات', 'student_discounts' => 'خصومات',
            'financial_transactions' => 'سجل مالي', 'discounts' => 'خصومات', 'audit_logs' => 'سجل تدقيق',
            'announcements' => 'إعلانات', 'exam_periods' => 'فترات نتائج', 'school_activities' => 'أنشطة',
            'tutor_conversations' => 'محادثات المساعد', 'fcm_notification_events' => 'سجل إشعارات',
        ];
        $references = $this->references($target, $id, $ignored);
        if ($references) {
            $reasons = array_unique(array_map(fn ($table) => $labels[$table] ?? 'سجل مرتبط محفوظ', $references));
            throw ValidationException::withMessages([$field => 'لا يمكن الحذف لوجود: '.implode('، ', $reasons).'. أوقف السجل بدل حذفه للحفاظ على التاريخ.']);
        }
    }

    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->id === auth()->id()) throw ValidationException::withMessages(['user' => 'لا يمكنك حذف حسابك.']);
            $this->guardLastAdministrator($user);
            if ($user->notifications()->exists()) throw ValidationException::withMessages(['user' => 'للحساب سجل إشعارات محفوظ. أوقف الحساب بدل حذفه.']);
            $this->guard('users', $user->id, 'user', ['staff_role_assignments', 'push_subscriptions', 'fcm_devices']);
            $old = $user->getAttributes();
            $user->staffRoles()->detach();
            $user->delete();
            AuditService::record('deleted', 'users', $user, $old);
        });
    }

    public function setUserStatus(User $user, string $status): void
    {
        DB::transaction(function () use ($user, $status): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->id === auth()->id() && $status !== 'active') throw ValidationException::withMessages(['user' => 'لا يمكنك إيقاف حسابك.']);
            if ($status !== 'active') $this->guardLastAdministrator($user);
            foreach (['students', 'teachers', 'guardians'] as $table) {
                if ($status === 'active' && DB::table($table)->where('user_id', $user->id)->whereNotNull('deleted_at')->exists()) {
                    throw ValidationException::withMessages(['user' => 'ملف الحساب مؤرشف. يجب استعادته بإجراء معتمد قبل تفعيل الدخول.']);
                }
                DB::table($table)->where('user_id', $user->id)->whereNull('deleted_at')->update(['status' => $status, 'updated_at' => now()]);
            }
            $user->update(['status' => $status]);
        }, 3);
    }

    public function guardLastAdministrator(User $user): void
    {
        if ($user->role === 'admin' && $user->status === 'active'
            && User::where('role', 'admin')->where('status', 'active')->orderBy('id')->lockForUpdate()->get()->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'يجب الإبقاء على مدير نظام نشط واحد على الأقل.']);
        }
    }
}
