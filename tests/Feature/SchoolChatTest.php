<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class SchoolChatTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private User $parent;

    private User $teacher;

    private User $admin;

    private Student $student;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = $this->createStudentFixture('CHAT')['student'];
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $this->guardian->students()->attach($this->student);
        $this->teacher = User::factory()->create(['name' => 'معلم المحادثة', 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $this->teacher->id, 'status' => 'active']);
        $subject = $this->createSubjectFor($this->student->classroom, 'CHAT');
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'subject_id' => $subject->id]);
        $this->admin = $this->createAdmin();
    }

    public function test_starting_chat_needs_only_a_contact_and_reuses_the_same_child_conversation(): void
    {
        $payload = ['student_id' => $this->student->id, 'recipient_id' => $this->teacher->id];
        $this->actingAs($this->parent)->post('/parent/conversations', $payload)->assertRedirect();
        $conversation = Conversation::firstOrFail();
        $this->post('/parent/conversations', $payload)->assertRedirect(route('parent.conversations.show', $conversation));
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 0);
        $this->get(route('parent.conversations.show', $conversation))->assertOk()->assertSeeText('ابدأ المحادثة بكتابة رسالتك في الأسفل.');
    }

    public function test_parent_and_teacher_can_exchange_messages_without_reloading_and_notifications_open_chat(): void
    {
        $conversation = $this->conversation($this->teacher);
        $this->actingAs($this->parent)->postJson(route('parent.conversations.messages.store', $conversation), ['body' => "السلام عليكم\nكيف مستوى الطالب؟"])
            ->assertCreated()->assertJsonPath('message.is_mine', true)->assertJsonPath('message.body', "السلام عليكم\nكيف مستوى الطالب؟");
        $this->assertSame(route('teacher.conversations.show', $conversation), $this->teacher->notifications()->firstOrFail()->data['url']);
        $this->actingAs($this->teacher)->get(route('teacher.conversations.index'))->assertOk()->assertSeeText($this->parent->name);
        $this->get(route('teacher.conversations.show', $conversation))->assertOk()->assertSeeText('كيف مستوى الطالب؟');
        $this->assertSame(0, $this->teacher->unreadNotifications()->count());
        $this->travel(1)->seconds();
        $reply = $this->postJson(route('teacher.conversations.messages.store', $conversation), ['body' => 'وعليكم السلام، مستوى الطالب جيد.'])
            ->assertCreated()->json('message');
        $this->assertSame(1, $this->parent->unreadNotifications()->count());
        $this->actingAs($this->parent)->getJson(route('parent.conversations.messages', $conversation).'?after='.($reply['id'] - 1).'&read=1')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.is_mine', false)->assertJsonPath('messages.0.body', 'وعليكم السلام، مستوى الطالب جيد.');
        $this->assertSame(0, $this->parent->unreadNotifications()->count());
        $this->get('/parent/messages')->assertOk()->assertSeeText('معلم المحادثة')->assertSeeText('وعليكم السلام، مستوى الطالب جيد.');
    }

    public function test_admin_can_receive_and_reply_to_their_own_parent_chats(): void
    {
        $conversation = $this->conversation($this->admin);
        $this->actingAs($this->parent)->post(route('parent.conversations.messages.store', $conversation), ['body' => 'استفسار عن مواعيد المدرسة'])->assertRedirect();
        $this->actingAs($this->admin)->get('/admin/conversations')->assertOk()->assertSeeText($this->parent->name);
        $this->get(route('admin.conversations.show', $conversation))->assertOk()->assertSeeText('استفسار عن مواعيد المدرسة');
        $this->postJson(route('admin.conversations.messages.store', $conversation), ['body' => 'الدوام يبدأ الساعة الثامنة.'])->assertCreated();
        $notification = $this->parent->notifications()->firstOrFail();
        $this->assertSame($conversation->id, $notification->data['conversation_id']);
        $this->actingAs($this->parent)->patchJson(route('parent.notifications.read', $notification))->assertOk()
            ->assertJsonPath('url', '/parent/conversations/'.$conversation->id);
    }

    public function test_history_is_ordered_and_loads_older_messages_without_skipping(): void
    {
        $conversation = $this->conversation($this->teacher);
        for ($i = 1; $i <= 125; $i++) {
            $conversation->messages()->create(['sender_id' => $this->teacher->id, 'body' => 'رسالة رقم '.$i]);
        }
        $this->actingAs($this->parent)->get(route('parent.conversations.show', $conversation))->assertOk()
            ->assertViewHas('messages', fn ($items) => $items->count() === 50 && $items->first()->body === 'رسالة رقم 76' && $items->last()->body === 'رسالة رقم 125');
        $this->getJson(route('parent.conversations.messages', $conversation).'?before=76')->assertOk()
            ->assertJsonCount(50, 'messages')->assertJsonPath('messages.0.body', 'رسالة رقم 26')->assertJsonPath('messages.49.body', 'رسالة رقم 75')->assertJsonPath('has_older', true);
        $this->getJson(route('parent.conversations.messages', $conversation).'?after=120')->assertOk()
            ->assertJsonCount(5, 'messages')->assertJsonPath('messages.0.body', 'رسالة رقم 121');
    }

    public function test_participation_and_current_child_link_are_required_for_every_chat_endpoint(): void
    {
        $conversation = $this->conversation($this->teacher);
        $outsider = $this->createAdmin();
        foreach (['parent' => User::factory()->create(['role' => 'parent', 'status' => 'active']), 'admin' => $outsider] as $prefix => $user) {
            if ($prefix === 'parent') {
                Guardian::create(['user_id' => $user->id, 'status' => 'active']);
            }
            $this->actingAs($user)->get(route($prefix.'.conversations.show', $conversation))->assertNotFound();
            $this->getJson(route($prefix.'.conversations.messages', $conversation))->assertNotFound();
            $this->postJson(route($prefix.'.conversations.messages.store', $conversation), ['body' => 'غير مسموح'])->assertNotFound();
        }
        $this->guardian->students()->detach();
        $this->actingAs($this->parent)->get('/parent/messages')->assertOk()->assertDontSeeText('معلم المحادثة');
        $this->get(route('parent.conversations.show', $conversation))->assertNotFound();
        $this->postJson(route('parent.conversations.messages.store', $conversation), ['body' => 'غير مسموح'])->assertNotFound();
    }

    public function test_unassigned_teacher_and_inactive_recipients_cannot_receive_new_child_messages(): void
    {
        $conversation = $this->conversation($this->teacher);
        $this->teacher->teacher->classrooms()->detach();
        $this->actingAs($this->teacher)->get('/teacher/conversations')->assertOk()->assertDontSeeText($this->parent->name);
        $this->get(route('teacher.conversations.show', $conversation))->assertNotFound();
        $this->postJson(route('teacher.conversations.messages.store', $conversation), ['body' => 'لا يسمح'])->assertNotFound();
        $this->actingAs($this->parent)->postJson(route('parent.conversations.messages.store', $conversation), ['body' => 'لا يسمح'])->assertForbidden();
        $adminChat = $this->conversation($this->admin);
        $this->admin->update(['status' => 'inactive']);
        $this->postJson(route('parent.conversations.messages.store', $adminChat), ['body' => 'لا يسمح'])->assertForbidden();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_closed_chats_and_invalid_messages_never_write_messages(): void
    {
        $conversation = $this->conversation($this->teacher);
        $this->actingAs($this->parent)->postJson(route('parent.conversations.messages.store', $conversation), ['body' => '   '])->assertUnprocessable();
        $this->postJson(route('parent.conversations.messages.store', $conversation), ['body' => str_repeat('a', 3001)])->assertUnprocessable();
        $conversation->update(['status' => 'closed']);
        $this->postJson(route('parent.conversations.messages.store', $conversation), ['body' => 'رسالة في محادثة مغلقة'])->assertForbidden();
        $this->get(route('parent.conversations.show', $conversation))->assertOk()->assertSeeText('هذه المحادثة مغلقة');
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_inbox_child_filter_and_unread_count_keep_other_children_separate(): void
    {
        $first = $this->conversation($this->teacher);
        $secondStudent = $this->createStudentFixture('CHAT-SECOND')['student'];
        $this->guardian->students()->attach($secondStudent);
        $second = $this->conversation($this->admin, $secondStudent);
        $first->messages()->create(['sender_id' => $this->teacher->id, 'body' => 'رد معلم الابن الأول']);
        $second->messages()->create(['sender_id' => $this->admin->id, 'body' => 'رد إدارة الابن الثاني']);
        $this->actingAs($this->parent)->get('/parent/messages')->assertOk()->assertViewHas('conversations', fn ($items) => $items->count() === 2 && $items->sum('unread_count') === 2);
        $this->get('/parent/messages?student='.$secondStudent->id)->assertOk()->assertSeeText('رد إدارة الابن الثاني')->assertDontSeeText('رد معلم الابن الأول');
        $this->get(route('parent.conversations.show', $first))->assertOk();
        $this->get('/parent/messages')->assertViewHas('conversations', fn ($items) => $items->sum('unread_count') === 1);
    }

    private function conversation(User $recipient, ?Student $student = null): Conversation
    {
        $conversation = Conversation::create(['student_id' => ($student ?? $this->student)->id, 'created_by' => $this->parent->id, 'status' => 'open', 'last_message_at' => now()]);
        $conversation->participants()->attach([$this->parent->id, $recipient->id]);

        return $conversation;
    }
}
