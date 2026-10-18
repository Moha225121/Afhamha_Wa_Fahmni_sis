<?php

namespace App\Services;

use App\Models\GuardianCall;
use App\Models\StudentNote;
use App\Models\User;
use App\Notifications\ParentFollowupNotification;
use App\Notifications\ParentPortalNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class ParentFollowupNotificationService
{
    public function sendNote(StudentNote $note): int
    {
        return $note->visibility === 'guardian' ? $this->send($note, 'student_note') : 0;
    }

    public function sendCall(GuardianCall $call): int
    {
        return $this->send($call, 'guardian_call');
    }

    /** Recover missed notifications from records the parent can still access. */
    public function syncFor(User $parent): array
    {
        $guardian = $parent->guardian;
        if (! $parent->isParent() || ! $guardian || $guardian->status !== 'active') {
            return [];
        }
        $studentIds = $guardian->students()->pluck('students.id');
        $existing = $parent->notifications()->where('type', ParentFollowupNotification::class)->toBase()->pluck('data', 'id')->all();
        $notes = StudentNote::with('student.user')->whereIn('student_id', $studentIds)->where('visibility', 'guardian')->get();
        $calls = GuardianCall::with(['student.user', 'note'])->whereIn('student_id', $studentIds)->get();
        $ids = [];
        foreach ($notes as $note) {
            $ids[] = $this->store($parent, $note, 'student_note', false, $existing);
        }
        foreach ($calls as $call) {
            $ids[] = $this->store($parent, $call, 'guardian_call', false, $existing);
        }

        return $ids;
    }

    public function unreadIds(User $parent, string $category): Collection
    {
        $ids = $this->syncFor($parent);
        $key = $category === 'student_note' ? 'student_note_id' : 'guardian_call_id';

        return $parent->notifications()->whereIn('id', $ids)->where('data->category', $category)
            ->whereNull('read_at')->get()->map(fn ($item) => (int) $item->data[$key]);
    }

    public function markRead(User $parent, StudentNote|GuardianCall $record): void
    {
        $category = $record instanceof StudentNote ? 'student_note' : 'guardian_call';
        $id = $this->store($parent, $record, $category);
        $parent->notifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    private function send(StudentNote|GuardianCall $record, string $category): int
    {
        $parents = $record->student->guardians()->where('guardians.status', 'active')->with('user')->get()
            ->pluck('user')->filter(fn ($user) => $user?->isParent())->unique('id');
        foreach ($parents as $parent) {
            $this->store($parent, $record, $category, true);
        }

        return $parents->count();
    }

    private function store(User $parent, StudentNote|GuardianCall $record, string $category, bool $push = false, ?array $existingById = null): string
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:parent-'.$category.':'.$record->id.':'.$parent->id)->toString();
        $isNote = $record instanceof StudentNote;
        $payload = [
            'title' => $isNote ? ($record->requires_guardian_call ? 'ملاحظة جديدة مع استدعاء ولي الأمر' : 'ملاحظة متابعة جديدة') : 'استدعاء ولي الأمر',
            'body' => $record->student->user->name.' — '.Str::limit($isNote ? $record->title : $record->parentReason(), 160),
            'category' => $category,
            'student_id' => $record->student_id,
            $isNote ? 'student_note_id' : 'guardian_call_id' => $record->id,
            'url' => route($isNote ? 'parent.student-followup.show' : 'parent.guardian-calls.show', $record, false),
        ];
        $data = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $existing = $existingById === null ? DB::table('notifications')->where('id', $id)->value('data') : ($existingById[$id] ?? null);
        if ($existing === null) {
            // Direct summons used to create generic alerts. Keep their read state and hide the duplicate in the feed.
            $legacyCandidates = $isNote ? collect() : $parent->notifications()->where('type', ParentPortalNotification::class)
                ->where('data->category', 'guardian_call')->where('data->student_id', $record->student_id)
                ->whereNull('data->guardian_call_id')->whereBetween('created_at', [
                    $record->created_at->copy()->subSeconds(5), $record->created_at->copy()->addSeconds(5),
                ])->get();
            $legacy = null;
            if ($legacyCandidates->count() === 1 && GuardianCall::where('student_id', $record->student_id)
                ->whereBetween('created_at', [$record->created_at->copy()->subSeconds(5), $record->created_at->copy()->addSeconds(5)])->count() === 1) {
                $legacy = $legacyCandidates->first();
            }
            $inserted = DB::table('notifications')->insertOrIgnore([
                'id' => $id, 'type' => ParentFollowupNotification::class,
                'notifiable_type' => $parent->getMorphClass(), 'notifiable_id' => $parent->id,
                'data' => $data, 'read_at' => $legacy?->read_at,
                'created_at' => $record->created_at ?? now(), 'updated_at' => now(),
            ]);
            if ($inserted && $push && ! $legacy) {
                DB::afterCommit(fn () => app(WebPushService::class)->send($parent, $payload));
            }
        } elseif ($existing !== $data) {
            DB::table('notifications')->where('id', $id)->update(['data' => $data, 'updated_at' => now()]);
        }

        return $id;
    }
}
