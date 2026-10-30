<?php

namespace App\Services;

use App\Models\ConversationMessageReceipt;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Child associations come from stored IDs, never names or message text. */
class ParentMessageScope
{
    public function children(User $parent): Collection
    {
        if ($parent->role !== 'parent' || $parent->status !== 'active' || $parent->guardian?->status !== 'active') {
            return collect();
        }

        return $parent->guardian->students()->where('students.status', 'active')
            ->whereHas('user', fn ($query) => $query->where('role', 'student')->where('status', 'active'))
            ->with(['user', 'classroom.academicYear'])->orderBy('student_number')->get();
    }

    public function studentIds(array $data): array
    {
        $values = array_key_exists('student_ids', $data) ? $data['student_ids'] : [$data['student_id'] ?? null];
        if (! is_array($values) || $values === []) {
            return [];
        }
        $ids = [];
        foreach ($values as $value) {
            $id = (is_int($value) || is_string($value)) ? filter_var($value, FILTER_VALIDATE_INT) : false;
            if ($id === false || $id < 1) {
                return [];
            }
            $ids[] = $id;
        }
        if (isset($data['student_id']) && ! in_array((int) $data['student_id'], $ids, true)) {
            return [];
        }

        return array_values(array_unique($ids));
    }

    public function includes(array $data, int $studentId): bool
    {
        return in_array($studentId, $this->studentIds($data), true);
    }

    /** Supports Eloquent relations/builders and correlated query builders using a qualified data column. */
    public function forStudent($query, int $studentId, string $dataColumn = 'notifications.data')
    {
        return $query->where(function ($scope) use ($studentId, $dataColumn): void {
            $scope->whereJsonContains($dataColumn.'->student_ids', $studentId)
                ->orWhere(function ($legacy) use ($studentId, $dataColumn): void {
                    $legacy->whereNull($dataColumn.'->student_ids')->where($dataColumn.'->student_id', $studentId);
                });
        });
    }

    public function notificationStudent(User $parent, array $data, ?int $preferredStudentId = null): ?Student
    {
        $ids = $this->studentIds($data);
        $children = $this->children($parent);
        if ($preferredStudentId !== null && in_array($preferredStudentId, $ids, true)) {
            $preferred = $children->firstWhere('id', $preferredStudentId);
            if ($preferred) {
                return $preferred;
            }
        }

        return $children->first(fn ($child) => in_array((int) $child->id, $ids, true));
    }

    /** Explicit child context is per tab; the shared context persists only ordinary GET navigation. */
    public function selected(Request $request): ?Student
    {
        $children = $this->children($request->user());
        if ($children->isEmpty()) {
            abort_if($request->query('student') !== null, 404);

            return null;
        }
        $requested = $request->query('student');
        if ($requested !== null) {
            abort_unless((is_int($requested) || is_string($requested)) && filter_var($requested, FILTER_VALIDATE_INT) > 0, 404);
        }
        $selected = app(ParentPortalContext::class)->selectedStudent($request->user()->guardian, $children, $requested);
        abort_unless($selected && $children->contains('id', $selected->id), 404);

        return $selected;
    }

    /** Counts only incoming, unviewed messages for each active linked child, without marking anything read. */
    public function unreadCounts(User $parent): array
    {
        $children = $this->children($parent);
        $counts = $children->mapWithKeys(fn ($child) => [(int) $child->id => 0])->all();
        if ($children->isEmpty()) {
            return $counts;
        }
        $messenger = app(PortalMessengerService::class);
        if ($messenger->available()) {
            $direct = ConversationMessageReceipt::query()->join('messages as child_message', 'child_message.id', '=', 'conversation_message_receipts.message_id')
                ->join('conversations as child_conversation', 'child_conversation.id', '=', 'child_message.conversation_id')
                ->where('recipient_user_id', $parent->id)->whereNull('viewed_at')
                ->whereIn('child_conversation.id', $messenger->conversations($parent)->select('conversations.id'))
                ->whereIn('child_conversation.student_id', array_keys($counts))->groupBy('child_conversation.student_id')
                ->selectRaw('child_conversation.student_id, COUNT(*) as unread_count')->pluck('unread_count', 'child_conversation.student_id');
            foreach ($direct as $id => $count) {
                $counts[$id] += (int) $count;
            }
        }
        $receipts = app(TeacherMessageReceiptService::class);
        foreach ($children as $child) {
            $broadcasts = $this->forStudent($parent->notifications()->where('data->category', 'teacher_notification'), (int) $child->id);
            if ($receipts->available()) {
                $broadcasts->whereExists(fn ($query) => $query->selectRaw('1')->from('teacher_message_receipts as child_receipt')
                    ->whereColumn('child_receipt.notification_id', 'notifications.id')->where('child_receipt.recipient_user_id', $parent->id)->whereNull('child_receipt.viewed_at'));
            } else {
                $broadcasts->whereNull('read_at');
            }
            $counts[$child->id] += $broadcasts->count();
        }

        return $counts;
    }
}
