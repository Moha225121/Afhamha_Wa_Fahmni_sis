<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Conversation;
use App\Models\Exam;
use App\Models\Guardian;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Services\FcmService;
use App\Services\NotificationDestination;
use App\Services\ParentNotificationService;
use App\Services\PushNotificationService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class NotificationNavigationTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private function learning(): array
    {
        $ctx = $this->createStudentFixture('NAV');
        $subject = $this->createSubjectFor($ctx['classroom'], 'NAV');
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $ctx['classroom']->id, 'subject_id' => $subject->id]);
        $common = ['teacher_id' => $teacher->id, 'classroom_id' => $ctx['classroom']->id, 'subject_id' => $subject->id, 'title' => 'Target resource'];
        $lesson = Lesson::create($common + ['content' => 'Synthetic lesson content', 'status' => 'published', 'published_at' => now()->subMinute()]);
        $assignment = Assignment::create($common + ['status' => 'active', 'published_at' => now()->subMinute(), 'due_at' => now()->addDay()]);
        $exam = Exam::create($common + ['starts_at' => now()->addDay(), 'duration_minutes' => 30, 'total_score' => 10, 'status' => 'scheduled']);

        return $ctx + compact('subject', 'teacher', 'lesson', 'assignment', 'exam');
    }

    private function guardian(array $ctx): User
    {
        $user = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $user->id, 'relationship' => 'father', 'status' => 'active']);
        $guardian->students()->attach($ctx['student']);

        return $user;
    }

    public static function types(): array
    {
        return array_map(fn ($type) => [$type], ['lesson', 'assignment', 'exam', 'grade', 'result', 'attendance', 'message', 'announcement', 'teacher_notification', 'admin_notification', 'staff_notification', 'general']);
    }

    #[DataProvider('types')]
    public function test_student_destinations_for_each_implemented_category(string $category): void
    {
        $ctx = $this->learning();
        $payload = ['title' => 'Notice', 'body' => 'Synthetic', 'category' => $category];
        $expected = route(match ($category) {
            'grade', 'result' => 'student.results', 'attendance' => 'student.attendance', 'message' => 'student.messages',
            default => 'student.notifications',
        });
        if (in_array($category, ['lesson', 'assignment', 'exam'])) {
            $payload[$category.'_id'] = $ctx[$category]->id;
            $expected = match ($category) {
                'lesson' => route('student.lessons.show', [$ctx['subject'], $ctx['lesson']]),
                'assignment' => route('student.assignments.show', $ctx['assignment']),
                'exam' => route('student.exams.index', ['exam' => $ctx['exam']->id]),
            };
        }
        if ($category === 'announcement') {
            $announcement = Announcement::create(['title' => 'Visible announcement', 'content' => 'Synthetic', 'audience' => 'students', 'status' => 'published', 'published_at' => now()->subMinute(), 'created_by' => $this->createAdmin()->id]);
            $payload['announcement_id'] = $announcement->id;
            $expected = route('student.announcements.show', $announcement);
        }
        $this->assertSame($expected, app(NotificationDestination::class)->for($ctx['user'], $payload));
        $this->actingAs($ctx['user'])->get($expected)->assertOk();
    }

    public function test_older_links_recover_ids_and_missing_ids_use_relevant_lists(): void
    {
        $ctx = $this->learning();
        $resolver = app(NotificationDestination::class);
        foreach (['lesson' => 'student.lessons.show', 'assignment' => 'student.assignments.show'] as $type => $route) {
            $url = $type === 'lesson' ? route($route, [$ctx['subject'], $ctx['lesson']]) : route($route, $ctx['assignment']);
            $this->assertSame($url, $resolver->for($ctx['user'], ['category' => $type, 'url' => $url]));
        }
        foreach (['lesson' => 'student.subjects.index', 'assignment' => 'student.assignments.index', 'exam' => 'student.exams.index'] as $type => $route) {
            $this->assertSame(route($route), $resolver->for($ctx['user'], ['category' => $type, 'url' => route('parent.dashboard')]));
        }
        $this->assertSame(route('student.notifications'), $resolver->for($ctx['user'], []));
    }

    public function test_bad_external_missing_and_unavailable_resources_fall_back_without_redirecting_outside(): void
    {
        $ctx = $this->learning();
        $resolver = app(NotificationDestination::class);
        $this->assertSame(route('student.notifications'), $resolver->for($ctx['user'], [
            'category' => 'assignment', 'lesson_id' => $ctx['lesson']->id, 'assignment_id' => ['invalid'],
        ]));
        foreach (['https://evil.test/student/results', '//evil.test/student/results', 'javascript:alert(1)', '/student/../login', '/student/%2e%2e/login', '/missing', ['bad']] as $url) {
            $this->assertSame(route('student.notifications'), $resolver->for($ctx['user'], ['category' => 'lesson', 'lesson_id' => $ctx['lesson']->id, 'url' => $url]));
        }
        foreach (['lesson', 'assignment', 'exam'] as $type) {
            foreach ([-1, 'abc', 999999, ['bad']] as $id) {
                $this->assertSame(route('student.notifications'), $resolver->for($ctx['user'], ['category' => $type, $type.'_id' => $id]));
            }
            $ctx[$type]->update(['status' => 'draft']);
            $this->assertSame(route('student.notifications'), $resolver->for($ctx['user'], ['category' => $type, $type.'_id' => $ctx[$type]->id]));
            $ctx[$type]->delete();
            $this->assertSame(route('student.notifications'), $resolver->for($ctx['user'], ['category' => $type, $type.'_id' => $ctx[$type]->id]));
        }
    }

    public function test_student_cannot_use_a_notification_to_open_another_class_or_student(): void
    {
        $ctx = $this->learning();
        $other = $this->createStudentFixture('OTHER');
        $resolver = app(NotificationDestination::class);
        foreach (['lesson', 'assignment', 'exam'] as $type) {
            $this->assertSame(route('student.notifications'), $resolver->for($other['user'], ['category' => $type, $type.'_id' => $ctx[$type]->id]));
        }
        $this->assertSame(route('student.notifications'), $resolver->for($ctx['user'], ['category' => 'grade', 'student_id' => $other['student']->id]));
        $this->actingAs($other['user'])->get(route('student.assignments.show', $ctx['assignment']))->assertNotFound();
        $this->get(route('student.lessons.show', [$ctx['subject'], $ctx['lesson']]))->assertNotFound();
        $this->get(route('student.exams.index', ['exam' => $ctx['exam']->id]))->assertNotFound();
    }

    public function test_future_or_cancelled_learning_resources_are_not_offered_as_destinations(): void
    {
        $ctx = $this->learning();
        foreach (['lesson', 'assignment'] as $type) {
            $ctx[$type]->update(['published_at' => now()->addDay()]);
            $this->assertSame(route('student.notifications'), app(NotificationDestination::class)->for($ctx['user'], ['category' => $type, $type.'_id' => $ctx[$type]->id]));
        }
        $ctx['exam']->update(['status' => 'cancelled']);
        $this->assertSame(route('student.notifications'), app(NotificationDestination::class)->for($ctx['user'], ['category' => 'exam', 'exam_id' => $ctx['exam']->id]));
    }

    public function test_guardian_uses_linked_child_and_current_resource_scope(): void
    {
        $ctx = $this->learning();
        $parent = $this->guardian($ctx);
        $resolver = app(NotificationDestination::class);
        foreach (['lesson', 'assignment', 'exam'] as $type) {
            $expected = $type === 'lesson' ? route('parent.lessons.show', [$ctx['student'], $ctx['lesson']])
                : route('parent.'.$type.'s', ['student' => $ctx['student']->id, $type => $ctx[$type]->id]);
            $this->assertSame($expected, $resolver->for($parent, ['category' => $type, $type.'_id' => $ctx[$type]->id]));
            $this->actingAs($parent)->get($expected)->assertOk()->assertSeeText('Target resource');
        }
        foreach (['grade' => 'results', 'attendance' => 'attendance', 'guardian_call' => 'guardian-calls'] as $type => $page) {
            $this->assertSame(route('parent.'.$page, ['student' => $ctx['student']->id]), $resolver->for($parent, ['category' => $type, 'student_id' => $ctx['student']->id]));
        }
        $parent->guardian->students()->detach($ctx['student']);
        $this->assertSame(route('parent.notifications'), $resolver->for($parent, ['category' => 'lesson', 'lesson_id' => $ctx['lesson']->id]));
        $this->get(route('parent.lessons.show', [$ctx['student'], $ctx['lesson']]))->assertNotFound();
    }

    public function test_messages_require_conversation_participation_and_a_linked_child(): void
    {
        $ctx = $this->learning();
        $parent = $this->guardian($ctx);
        $other = $this->guardian($ctx);
        $conversation = Conversation::create(['student_id' => $ctx['student']->id, 'created_by' => $parent->id, 'status' => 'open']);
        $conversation->participants()->attach($parent->id);
        $data = ['category' => 'message', 'conversation_id' => $conversation->id];
        $this->assertSame(route('parent.conversations.show', $conversation), app(NotificationDestination::class)->for($parent, $data));
        $this->assertSame(route('parent.notifications'), app(NotificationDestination::class)->for($other, $data));
        $this->actingAs($other)->get(route('parent.conversations.show', $conversation))->assertNotFound();
    }

    public function test_read_action_and_parent_details_preserve_history_and_only_mark_the_owned_record(): void
    {
        $ctx = $this->learning();
        $other = $this->createStudentFixture('READ-OTHER');
        $ctx['user']->notify(new ParentPortalNotification(['title' => 'Homework notice', 'body' => 'Synthetic', 'category' => 'assignment', 'assignment_id' => $ctx['assignment']->id]));
        $record = $ctx['user']->notifications()->sole();
        $data = $record->data;
        $this->actingAs($other['user'])->patchJson(route('student.notifications.read', $record))->assertNotFound();
        $this->assertNull($record->fresh()->read_at);
        $this->actingAs($ctx['user'])->get(route('student.notifications'))->assertOk()->assertSeeText('عرض التفاصيل');
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.0.url', route('student.assignments.show', $ctx['assignment']));
        $this->patchJson(route('student.notifications.read', $record))->assertJsonPath('url', route('student.assignments.show', $ctx['assignment']))->assertJsonPath('unread_count', 0);
        $this->assertNotNull($record->fresh()->read_at);
        $this->assertSame($data, $record->fresh()->data);
        $this->assertSame(1, $ctx['user']->notifications()->count());
        $parent = $this->guardian($ctx);
        $parent->notify(new ParentPortalNotification(['title' => 'Lesson notice', 'body' => 'Synthetic', 'category' => 'lesson', 'lesson_id' => $ctx['lesson']->id, 'url' => route('parent.dashboard')]));
        $this->actingAs($parent)->get(route('parent.notifications'))->assertOk()->assertSee('class="text-action" href="'.route('parent.lessons.show', [$ctx['student'], $ctx['lesson']]).'"', false);
        $this->assertNull($parent->notifications()->sole()->read_at);
    }

    public function test_both_push_transports_receive_the_same_resolved_destination(): void
    {
        $ctx = $this->learning();
        $expected = route('student.lessons.show', [$ctx['subject'], $ctx['lesson']], false);
        $this->mock(FcmService::class)->shouldReceive('send')->once()->withArgs(fn ($user, $payload) => $user->is($ctx['user']) && $payload['url'] === $expected)
            ->andReturn(['device_ids' => [], 'endpoint_hashes' => []]);
        $this->mock(WebPushService::class)->shouldReceive('send')->once()->withArgs(fn ($user, $payload) => $user->is($ctx['user']) && $payload['url'] === $expected)->andReturn(0);
        app(PushNotificationService::class)->send($ctx['user'], ['category' => 'lesson', 'lesson_id' => $ctx['lesson']->id, 'url' => route('parent.dashboard')]);
    }

    public function test_teacher_destinations_do_not_grant_access_to_another_teachers_resources(): void
    {
        $ctx = $this->learning();
        $other = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $other->id, 'status' => 'active']);
        foreach (['lesson' => 'teacher.lessons.show', 'assignment' => 'teacher.assignments.edit', 'exam' => 'teacher.exams.edit'] as $type => $route) {
            $data = ['category' => $type, $type.'_id' => $ctx[$type]->id];
            $this->assertSame(route($route, $ctx[$type]), app(NotificationDestination::class)->for($ctx['teacher']->user, $data));
            $this->assertSame(route('teacher.dashboard'), app(NotificationDestination::class)->for($other, $data));
            $this->actingAs($other)->get(route($route, $ctx[$type]))->assertNotFound();
        }
        DB::table('teacher_assignments')->where('teacher_id', $ctx['teacher']->id)->delete();
        $this->assertSame(route('teacher.dashboard'), app(NotificationDestination::class)->for($ctx['teacher']->user, ['category' => 'lesson', 'lesson_id' => $ctx['lesson']->id]));
    }

    public function test_parent_announcements_use_the_authorized_feed_and_withdrawn_announcements_fall_back(): void
    {
        $ctx = $this->learning();
        $parent = $this->guardian($ctx);
        $announcement = Announcement::create(['title' => 'Parent announcement', 'content' => 'Synthetic', 'audience' => 'parents', 'status' => 'published', 'published_at' => now()->subMinute(), 'created_by' => $this->createAdmin()->id]);
        $data = ['category' => 'announcement', 'announcement_id' => $announcement->id];
        $this->assertSame(route('parent.ads.index'), app(NotificationDestination::class)->for($parent, $data));
        $this->assertSame(route('student.notifications'), app(NotificationDestination::class)->for($ctx['user'], $data));
        $parent->guardian->students()->detach($ctx['student']);
        $this->assertSame(route('parent.ads.index'), app(NotificationDestination::class)->for($parent, $data));
        $this->actingAs($parent)->get(route('parent.ads.index'))->assertOk()->assertSeeText('Parent announcement');
        $announcement->update(['status' => 'draft']);
        $this->assertSame(route('parent.notifications'), app(NotificationDestination::class)->for($parent, $data));
    }

    public function test_unavailable_publication_and_foreign_child_query_cannot_be_used_as_navigation(): void
    {
        $ctx = $this->learning();
        $parent = $this->guardian($ctx);
        $other = $this->createStudentFixture('FOREIGN-CHILD');
        $this->assertSame(route('parent.notifications'), app(NotificationDestination::class)->for($parent, ['category' => 'grade', 'url' => route('parent.results', ['student' => $other['student']->id])]));
        $this->assertSame(route('student.notifications'), app(NotificationDestination::class)->for($ctx['user'], ['category' => 'grade', 'exam_period_id' => 999999]));
        $this->actingAs($parent)->get(route('parent.results', ['student' => $other['student']->id]))->assertNotFound();
        $this->get(route('parent.lessons.show', [$other['student'], $ctx['lesson']]))->assertNotFound();
        $this->get(route('parent.exams', ['student' => $other['student']->id, 'exam' => $ctx['exam']->id]))->assertNotFound();
        $ctx['lesson']->update(['status' => 'draft']);
        $this->get(route('parent.lessons.show', [$ctx['student'], $ctx['lesson']]))->assertNotFound();
    }

    public function test_desktop_and_mobile_user_agents_share_the_same_read_destination_and_authentication(): void
    {
        $ctx = $this->learning();
        $ctx['user']->notify(new ParentPortalNotification(['title' => 'Homework notice', 'body' => 'Synthetic', 'category' => 'assignment', 'assignment_id' => $ctx['assignment']->id]));
        $record = $ctx['user']->notifications()->sole();
        $this->patchJson(route('student.notifications.read', $record))->assertUnauthorized();
        foreach (['Mozilla/5.0 Windows Chrome', 'Mozilla/5.0 iPhone Mobile Safari'] as $agent) {
            $this->actingAs($ctx['user'])->withHeader('User-Agent', $agent)->get(route('student.notifications'))->assertOk()->assertSee(route('student.notifications.read', $record));
            $this->patch(route('student.notifications.read', $record))->assertRedirect(route('student.assignments.show', $ctx['assignment']));
        }
        if (getenv('NAVIGATION_LAYOUT_EXPORT')) {
            URL::forceRootUrl('http://127.0.0.1:8767');
            try {
                file_put_contents(base_path('tmp/navigation-student.html'), $this->get(route('student.notifications'))->getContent());
                $parent = $this->guardian($ctx);
                $parent->notify(new ParentPortalNotification(['title' => 'Lesson notice', 'body' => 'Synthetic', 'category' => 'lesson', 'lesson_id' => $ctx['lesson']->id]));
                file_put_contents(base_path('tmp/navigation-parent.html'), $this->actingAs($parent)->get(route('parent.notifications'))->getContent());
            } finally {
                URL::forceRootUrl(null);
            }
        }
    }

    public function test_database_payload_retains_navigation_resource_ids(): void
    {
        $payload = ['title' => 'Lesson', 'body' => 'Published', 'category' => 'lesson', 'lesson_id' => 12];
        $this->assertSame(12, (new ParentPortalNotification($payload))->toDatabase(new User)['lesson_id'] ?? null);
    }

    public function test_push_fallback_resolves_the_authenticated_role_instead_of_the_generic_home_page(): void
    {
        $this->get('/notifications')->assertRedirect(route('login'));
        foreach (['student' => 'student.notifications', 'parent' => 'parent.notifications', 'teacher' => 'teacher.dashboard', 'admin' => 'admin.notifications.index'] as $role => $route) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get('/notifications')->assertRedirect(route($route));
        }
    }

    public function test_student_destination_overrides_a_legacy_parent_dashboard_url(): void
    {
        $ctx = $this->createStudentFixture('NAV-LEGACY');
        app(ParentNotificationService::class)->sendToStudents([$ctx['classroom']->id], [
            'title' => 'Homework', 'body' => 'Published', 'category' => 'assignment',
            'url' => route('parent.dashboard'),
        ], null, route('student.assignments.index'));
        $this->assertSame(route('student.assignments.index'), $ctx['user']->notifications()->sole()->data['url']);
    }
}
