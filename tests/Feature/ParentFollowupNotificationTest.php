<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\GuardianCall;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class ParentFollowupNotificationTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private User $supervisor;

    private User $parent;

    private Guardian $guardian;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = $this->createStudentFixture('FOLLOWUP')['student'];
        $this->student->user->update(['name' => 'طالب المتابعة']);
        $this->supervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $this->supervisor->supervisedClassrooms()->attach($this->student->classroom_id);
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $this->guardian->students()->attach($this->student);
    }

    public function test_supervisor_note_with_call_notifies_parent_and_opens_both_details(): void
    {
        $this->actingAs($this->supervisor)->post('/supervisor/student-notes', $this->payload(['requires_guardian_call' => 1]))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'تم حفظ الملاحظة وإشعار ولي الأمر.');
        $note = StudentNote::firstOrFail();
        $call = GuardianCall::firstOrFail();
        $this->assertSame($note->id, $call->student_note_id);
        $this->assertSame('notified', $call->status);
        $this->assertSame(2, $this->parent->unreadNotifications()->count());
        $this->actingAs($this->parent)->get('/parent/student-followup')->assertOk()->assertSeeText('جديد')
            ->assertSeeText('استدعاء ولي الأمر')->assertSeeText('عرض تفاصيل الملاحظة');
        $summary = $this->getJson('/parent/notifications/summary')->assertOk()->assertJsonPath('unread_count', 2)
            ->assertJsonPath('unread_by_category.student_note', 1)->assertJsonPath('unread_by_category.guardian_call', 1);
        $notification = collect($summary->json('notifications'))->firstWhere('category', 'student_note');
        $this->patchJson($notification['read_url'])->assertOk()->assertJsonPath('url', '/parent/student-followup/'.$note->id);
        $this->get('/parent/student-followup/'.$note->id)->assertOk()->assertSeeText('تفاصيل الملاحظة')
            ->assertSeeText('يرجى متابعة الواجب اليومي مع الطالب.')
            ->assertSee('href="'.route('parent.guardian-calls.show', $call).'"', false);
        $this->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 1);
        $this->get('/parent/guardian-calls/'.$call->id)->assertOk()->assertSeeText('تم الإبلاغ')
            ->assertSeeText('يرجى متابعة الواجب اليومي مع الطالب.')
            ->assertSee('href="'.route('parent.student-followup.show', $note).'"', false);
        $this->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 0);
        $this->getJson('/parent/notifications/summary')->assertJsonCount(2, 'notifications');
        $this->assertSame(2, $this->parent->notifications()->count());
    }

    public function test_visible_note_without_call_notifies_and_opens_directly_from_the_list(): void
    {
        $this->actingAs($this->supervisor)->post('/supervisor/students/'.$this->student->id.'/notes', $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('guardian_calls', 0);
        $note = StudentNote::firstOrFail();
        $this->actingAs($this->parent)->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 1);
        $this->get('/parent/student-followup/'.$note->id)->assertOk()->assertSeeText($note->content);
        $this->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 0);
    }

    public function test_internal_notes_stay_private_even_when_they_request_a_guardian_call(): void
    {
        $private = $this->payload(['title' => 'عنوان داخلي سري', 'content' => 'محتوى داخلي سري', 'visibility' => 'internal']);
        $this->actingAs($this->supervisor)->post('/supervisor/student-notes', $private)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 0);
        $this->post('/supervisor/student-notes', $private + ['requires_guardian_call' => 1])->assertSessionHasNoErrors();
        $call = GuardianCall::firstOrFail();
        $this->actingAs($this->parent)->get('/parent/student-followup')->assertOk()->assertDontSeeText('عنوان داخلي سري')->assertDontSeeText('محتوى داخلي سري');
        $this->get('/parent/guardian-calls')->assertOk()->assertSeeText('استدعاء ولي الأمر')->assertDontSeeText('عنوان داخلي سري')->assertDontSeeText('محتوى داخلي سري');
        $this->get('/parent/guardian-calls/'.$call->id)->assertOk()->assertDontSeeText('عنوان داخلي سري')->assertDontSeeText('محتوى داخلي سري')->assertDontSeeText('عرض الملاحظة المرتبطة');
        $this->get('/parent/student-followup/'.$call->student_note_id)->assertNotFound();
        $this->getJson('/parent/notifications/summary')->assertJsonCount(1, 'notifications')
            ->assertDontSee('عنوان داخلي سري')->assertDontSee('محتوى داخلي سري');
    }

    public function test_previously_missed_notes_and_calls_are_recovered_once(): void
    {
        $note = $this->note(['requires_guardian_call' => true]);
        $call = $this->createCall(['student_note_id' => $note->id]);
        $this->assertDatabaseCount('notifications', 0);
        $this->actingAs($this->parent)->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 2);
        $this->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->patchJson('/parent/notifications/read-all')->assertJsonPath('unread_count', 0);
        $this->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 0);
        $this->get('/parent/student-followup/'.$note->id)->assertSeeText('استدعاء ولي الأمر');
        $this->get('/parent/guardian-calls/'.$call->id)->assertOk();
    }

    public function test_old_direct_call_notification_is_replaced_without_losing_its_read_state(): void
    {
        $call = $this->createCall();
        $this->parent->notify(new ParentPortalNotification([
            'title' => 'استدعاء ولي الأمر', 'body' => 'طلب حضور ولي الأمر', 'category' => 'guardian_call',
            'student_id' => $this->student->id, 'url' => route('parent.guardian-calls', ['student' => $this->student->id]),
        ]));
        $legacy = $this->parent->notifications()->firstOrFail();
        $legacy->markAsRead();
        $this->actingAs($this->parent)->getJson('/parent/notifications/summary')->assertJsonPath('unread_count', 0)
            ->assertJsonCount(1, 'notifications')->assertJsonPath('notifications.0.is_read', true)
            ->assertJsonPath('notifications.0.url', '/parent/guardian-calls/'.$call->id);
        $this->patchJson(route('parent.notifications.read', $legacy))->assertNotFound();
    }

    public function test_hidden_or_deleted_notes_disappear_from_notifications_and_linked_call_hides_copied_content(): void
    {
        $note = $this->note(['content' => 'تفاصيل تم سحبها']);
        $call = $this->createCall(['student_note_id' => $note->id, 'guardian_visible_details' => $note->content]);
        $summary = $this->actingAs($this->parent)->getJson('/parent/notifications/summary');
        $noteAlert = collect($summary->json('notifications'))->firstWhere('category', 'student_note');
        $note->update(['visibility' => 'internal']);
        $this->getJson('/parent/notifications/summary')->assertJsonCount(1, 'notifications')->assertJsonPath('unread_by_category.student_note', 0);
        $this->patchJson($noteAlert['read_url'])->assertNotFound();
        $this->get('/parent/student-followup/'.$note->id)->assertNotFound();
        $this->get('/parent/guardian-calls/'.$call->id)->assertOk()->assertDontSeeText('تفاصيل تم سحبها');
        $call->delete();
        $this->getJson('/parent/notifications/summary')->assertJsonCount(0, 'notifications');
    }

    public function test_ambiguous_old_call_alert_does_not_mark_another_summons_read(): void
    {
        $this->createCall(['reason' => 'الاستدعاء الأول']);
        $this->createCall(['reason' => 'الاستدعاء الثاني']);
        $this->parent->notify(new ParentPortalNotification([
            'title' => 'استدعاء ولي الأمر', 'body' => 'طلب حضور ولي الأمر', 'category' => 'guardian_call',
            'student_id' => $this->student->id, 'url' => route('parent.guardian-calls'),
        ]));
        $this->parent->notifications()->firstOrFail()->markAsRead();
        $this->actingAs($this->parent)->getJson('/parent/notifications/summary')
            ->assertJsonCount(2, 'notifications')->assertJsonPath('unread_count', 2);
    }

    public function test_only_linked_active_guardians_are_notified_and_unlinking_removes_access(): void
    {
        $otherParent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $otherGuardian = Guardian::create(['user_id' => $otherParent->id, 'status' => 'inactive']);
        $otherGuardian->students()->attach($this->student);
        $this->actingAs($this->supervisor)->post('/supervisor/student-notes', $this->payload(['requires_guardian_call' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(0, $otherParent->notifications()->count());
        $this->assertSame(2, $this->parent->notifications()->count());
        $note = StudentNote::firstOrFail();
        $call = GuardianCall::firstOrFail();
        $this->guardian->students()->detach($this->student);
        $this->actingAs($this->parent)->getJson('/parent/notifications/summary')->assertJsonCount(0, 'notifications')->assertJsonPath('unread_count', 0);
        $this->get('/parent/student-followup/'.$note->id)->assertNotFound();
        $this->get('/parent/guardian-calls/'.$call->id)->assertNotFound();
    }

    public function test_unassigned_supervisor_and_unlinked_parent_cannot_access_followups(): void
    {
        $other = $this->createStudentFixture('FOLLOWUP-OTHER')['student'];
        $this->actingAs($this->supervisor)->post('/supervisor/student-notes', $this->payload(['student_id' => $other->id]))->assertForbidden();
        $note = $this->note(['student_id' => $other->id]);
        $call = $this->createCall(['student_id' => $other->id]);
        $this->actingAs($this->parent)->get('/parent/student-followup/'.$note->id)->assertNotFound();
        $this->get('/parent/guardian-calls/'.$call->id)->assertNotFound();
        $this->get('/parent/student-followup?student='.$other->id)->assertNotFound();
        $this->get('/parent/guardian-calls?student='.$other->id)->assertNotFound();
    }

    public function test_default_followup_selects_child_with_new_records_and_explicit_child_selection_is_respected(): void
    {
        $second = $this->createStudentFixture('SECOND-FOLLOWUP')['student'];
        $this->guardian->students()->attach($second);
        $this->note(['student_id' => $second->id]);
        $this->createCall(['student_id' => $second->id]);
        $this->actingAs($this->parent)->get('/parent/student-followup')->assertOk()
            ->assertViewHas('selectedStudent', fn ($student) => $student->is($second))->assertSeeText('1 جديد');
        $this->get('/parent/guardian-calls')->assertOk()->assertViewHas('selectedStudent', fn ($student) => $student->is($second));
        $this->get('/parent/student-followup?student='.$this->student->id)->assertOk()
            ->assertViewHas('selectedStudent', fn ($student) => $student->is($this->student));
    }

    private function payload(array $attributes = []): array
    {
        return $attributes + ['student_id' => $this->student->id, 'type' => 'behavioral', 'title' => 'متابعة الواجب اليومي',
            'content' => 'يرجى متابعة الواجب اليومي مع الطالب.', 'visibility' => 'guardian'];
    }

    private function note(array $attributes = []): StudentNote
    {
        return StudentNote::create($attributes + $this->payload() + ['supervisor_id' => $this->supervisor->id]);
    }

    private function createCall(array $attributes = []): GuardianCall
    {
        return GuardianCall::create($attributes + ['student_id' => $this->student->id, 'supervisor_id' => $this->supervisor->id,
            'category' => 'behavior', 'reason' => 'مراجعة المدرسة', 'details' => 'بيانات داخلية لا تعرض',
            'guardian_visible_details' => 'يرجى التواصل لتحديد موعد.', 'status' => 'pending', 'requested_date' => today()]);
    }
}
