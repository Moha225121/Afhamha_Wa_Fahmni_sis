<?php

namespace Tests\Feature;

use App\Jobs\SendTeacherNotification;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PushNotificationService;
use App\Services\TeacherNotificationAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresFcm;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherMessagingTest extends TestCase
{
    use ConfiguresFcm, CreatesStudentEducationFixtures, RefreshDatabase;

    private const MESSAGE = 'يرجى مراجعة الواجب قبل الحصة القادمة.';

    private User $actor;

    private Teacher $teacher;

    private array $a;

    private array $b;

    private array $c;

    private array $foreign;

    private User $unlinked;

    private Guardian $sharedParent;

    private Guardian $coParent;

    private Guardian $cParent;

    private Guardian $foreignParent;

    private array $subjects = [];

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFcm();
        config([
            'queue.connections.database.connection' => config('database.default'),
            'queue.batching.database' => config('database.default'),
            'services.web_push.public_key' => null,
            'services.web_push.private_key' => null,
        ]);
        // Keep real database notification persistence, but never contact a push provider.
        $this->mock(PushNotificationService::class)->shouldReceive('send')
            ->andReturnUsing(function (User $user, array $payload): array {
                $this->pushes[] = ['user_id' => $user->id, 'payload' => $payload];

                return ['fcm_accepted' => 1, 'web_push_accepted' => 0];
            });

        $this->actor = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->actor->id, 'status' => 'active']);
        foreach (['a' => 'أحمد سالم', 'b' => 'ليلى سالم', 'c' => 'مريم عمر', 'foreign' => 'خالد خارج النطاق'] as $key => $name) {
            $this->{$key} = $this->createStudentFixture('MESSAGE-'.$key);
            $this->{$key}['user']->update(['name' => $name]);
            $this->{$key}['classroom']->update(['name' => 'الصف الأول', 'section' => strtoupper($key === 'foreign' ? 'a' : $key)]);
        }
        foreach (['a', 'b', 'c'] as $key) {
            $classroom = $this->{$key}['classroom'];
            $classroom->update(['academic_year_id' => $this->a['classroom']->academic_year_id]);
            $this->subjects[$key] = $this->createSubjectFor($classroom, 'MESSAGE-'.$key);
            $this->teacher->classrooms()->attach($classroom->id, ['subject_id' => $this->subjects[$key]->id]);
        }
        $this->unlinked = User::factory()->create(['name' => 'سارة بلا رابط', 'role' => 'student', 'status' => 'active']);
        Student::create([
            'user_id' => $this->unlinked->id, 'student_number' => 'MESSAGE-UNLINKED',
            'classroom_id' => $this->c['classroom']->id, 'status' => 'active',
        ]);
        $this->sharedParent = $this->parentFor('ولي أحمد وليلى', [$this->a['student'], $this->b['student'], $this->foreign['student']]);
        $this->coParent = $this->parentFor('ولي إضافي لأحمد', [$this->a['student']]);
        $this->cParent = $this->parentFor('ولي مريم', [$this->c['student']]);
        $this->foreignParent = $this->parentFor('ولي خارج النطاق', [$this->foreign['student']]);
    }

    private function parentFor(string $name, array $students): Guardian
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $user->id, 'status' => 'active']);
        $guardian->students()->attach(collect($students)->pluck('id')->all());

        return $guardian;
    }

    private function group(array $keys = ['a', 'b', 'c'], string $audience = 'families', string $mode = 'grade'): array
    {
        return [
            'audience' => $audience, 'selection_mode' => $mode,
            'classroom_ids' => array_map(fn (string $key) => $this->{$key}['classroom']->id, $keys),
        ];
    }

    private function people(array $ids, string $audience = 'families'): array
    {
        return [
            'audience' => $audience, 'selection_mode' => 'people',
            $audience === 'parents' ? 'guardian_ids' : 'student_ids' => $ids,
        ];
    }

    private function data(array $filters): array
    {
        return $filters + ['title' => 'رسالة المعلم', 'message' => self::MESSAGE];
    }

    private function previewId(array $filters): string
    {
        return $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data($filters))
            ->assertOk()->viewData('previewId');
    }

    private function confirm(string $id)
    {
        return $this->post('/teacher/notifications', ['preview_id' => $id, 'confirmed' => 1]);
    }

    private function work(): void
    {
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
    }

    private function recipientIds(array $filters): array
    {
        return app(TeacherNotificationAudience::class)->resolve($this->teacher, $filters)->pluck('id')->sort()->values()->all();
    }

    private function assertRecipients(array $expected, array $filters): void
    {
        sort($expected);
        $this->assertSame($expected, $this->recipientIds($filters));
    }

    private function body(User $user): string
    {
        return $user->notifications()->sole()->data['body'];
    }

    private function addSecondGrade(): array
    {
        $this->c['classroom']->update(['name' => 'الصف السادس', 'section' => 'A']);
        $secondBranch = $this->createStudentFixture('MESSAGE-SIXTH-B');
        $secondBranch['classroom']->update([
            'name' => 'الصف السادس', 'section' => 'B',
            'academic_year_id' => $this->a['classroom']->academic_year_id,
        ]);
        $subject = $this->createSubjectFor($secondBranch['classroom'], 'MESSAGE-SIXTH-B');
        $this->teacher->classrooms()->attach($secondBranch['classroom']->id, ['subject_id' => $subject->id]);

        return $secondBranch;
    }

    public function test_grade_selection_uses_exact_assigned_class_ids_not_matching_names_and_preview_does_not_send(): void
    {
        $filters = $this->group();
        $this->assertRecipients([
            $this->a['user']->id, $this->b['user']->id, $this->c['user']->id, $this->unlinked->id,
            $this->sharedParent->user_id, $this->coParent->user_id, $this->cParent->user_id,
        ], $filters);
        $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data($filters))
            ->assertOk()->assertViewHas('count', 7)->assertDontSee($this->foreign['user']->name);
        $this->previewId($filters);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('job_batches', 0);
        $this->assertSame([], $this->pushes);
    }

    public function test_long_arabic_message_is_complete_and_escaped_in_review_edit_and_stored_deliveries(): void
    {
        $message = str_repeat("رسالة عربية طويلة للأهل والطلاب، مع تفاصيل الواجب وخطوات المراجعة.\n", 220)
            ."\nنهاية الرسالة: <strong>نص حرفي</strong> & <script>alert('test')</script>";
        $this->assertGreaterThan(10000, mb_strlen($message));
        $data = array_replace($this->data($this->people([$this->a['user']->id])), ['message' => $message]);
        $review = $this->actingAs($this->actor)->post('/teacher/notifications/preview', $data)
            ->assertOk()->assertViewHas('count', 3)->assertSee(e($message), false)
            ->assertDontSee("<script>alert('test')</script>", false)->assertDontSee('<strong>نص حرفي</strong>', false);
        $this->assertSame($message, $review->viewData('data')['message']);
        $id = $review->viewData('previewId');
        $this->post('/teacher/notifications/preview', $data + ['edit' => 1])->assertOk()
            ->assertViewHas('draftMessage', $message)->assertSee(e($message), false);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);

        $this->confirm($id)->assertRedirect();
        $this->work();
        $this->assertSame($message, $this->body($this->a['user']));
        $this->assertSame('بخصوص: '.$this->a['user']->name."\n\n".$message, $this->body($this->sharedParent->user));
        $this->assertSame('بخصوص: '.$this->a['user']->name."\n\n".$message, $this->body($this->coParent->user));
        $this->assertDatabaseCount('notifications', 3);
        $this->assertCount(3, $this->pushes);
        $this->actingAs($this->a['user'])->get($this->a['user']->notifications()->sole()->data['url'])
            ->assertOk()->assertSee(e($message), false)->assertDontSee("<script>alert('test')</script>", false);
        $parentBody = $this->body($this->sharedParent->user);
        $this->actingAs($this->sharedParent->user)->get($this->sharedParent->user->notifications()->sole()->data['url'])
            ->assertOk()->assertSee(e($parentBody), false)->assertDontSee('<strong>نص حرفي</strong>', false);
    }

    public function test_review_has_one_confirmation_action_and_omitting_confirmation_still_rejects_sending(): void
    {
        $review = $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data($this->people([$this->unlinked->id])))
            ->assertOk()->assertViewHas('count', 1)
            ->assertDontSee('طالب دون ولي أمر مرتبط')->assertDontSee('تُضاف أسماء الأبناء المعنيين');
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($review->getContent(), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new \DOMXPath($document);
        $form = '//form[@data-teacher-notification-submit]';
        $this->assertSame(1, $xpath->query($form)->length);
        $this->assertSame(0, $xpath->query($form.'//input[@type="checkbox"]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " tm-demo-banner ")]')->length);
        $confirmation = $xpath->query($form.'//input[@type="hidden" and @name="confirmed" and @value="1"]');
        $this->assertSame(1, $confirmation->length);
        $buttons = $xpath->query($form.'//button[@type="submit"]');
        $this->assertSame(1, $buttons->length);
        $this->assertStringContainsString('tm-send', $buttons->item(0)->getAttribute('class'));
        $id = $review->viewData('previewId');

        $this->postJson('/teacher/notifications', ['preview_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->post('/teacher/notifications', [
            'preview_id' => $id, $confirmation->item(0)->getAttribute('name') => $confirmation->item(0)->getAttribute('value'),
        ])->assertRedirect(route('teacher.notifications.show', $id));
        $this->work();
        $this->assertSame(self::MESSAGE, $this->body($this->unlinked));
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_unlimited_body_still_requires_a_nonempty_string(): void
    {
        foreach (['', '   ', null, []] as $message) {
            $this->actingAs($this->actor)->postJson('/teacher/notifications/preview', array_replace(
                $this->data($this->people([$this->unlinked->id])), ['message' => $message],
            ))->assertUnprocessable()->assertJsonValidationErrors('message');
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_editing_a_legacy_preview_accepts_an_empty_selection_mode_without_sending(): void
    {
        $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data([
            'audience' => 'student', 'recipient_id' => $this->a['user']->id,
            'selection_mode' => null, 'edit' => 1,
        ]))->assertOk()->assertViewIs('teacher.notifications.create')
            ->assertViewHas('draftTitle', 'رسالة المعلم')->assertViewHas('draftMessage', self::MESSAGE);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_editing_a_messaging_preview_restores_only_the_selected_parent_users_and_draft(): void
    {
        $response = $this->actingAs($this->actor)->post('/teacher/notifications/preview',
            $this->data($this->people([$this->sharedParent->user_id], 'parents')) + ['edit' => 1]);
        $response->assertOk()->assertViewHas('draftMessage', self::MESSAGE);
        $data = $response->viewData('messagingData');
        $this->assertFalse($data['previewOnly']);
        $this->assertSame('parents', $data['initialState']['audience']);
        $this->assertSame('people', $data['initialState']['mode']);
        $this->assertSame([$this->sharedParent->user_id], $data['initialState']['personIds']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_two_complete_grades_can_be_selected_together_using_only_their_existing_class_ids(): void
    {
        $secondBranch = $this->addSecondGrade();
        $filters = $this->group(['a', 'b', 'c'], 'families', 'branches');
        $filters['classroom_ids'][] = $secondBranch['classroom']->id;
        $before = DB::table('classrooms')->orderBy('id')->get()->toJson();

        $this->assertRecipients([
            $this->a['user']->id, $this->b['user']->id, $this->c['user']->id,
            $this->unlinked->id, $secondBranch['user']->id,
            $this->sharedParent->user_id, $this->coParent->user_id, $this->cParent->user_id,
        ], $filters);
        $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data($filters))
            ->assertOk()->assertViewHas('count', 8)->assertDontSee($this->foreign['user']->name);
        $this->assertSame($before, DB::table('classrooms')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_branch_subsets_from_two_grades_do_not_include_other_branches_from_either_grade(): void
    {
        $secondBranch = $this->addSecondGrade();
        $filters = $this->group(['a'], 'families', 'branches');
        $filters['classroom_ids'][] = $secondBranch['classroom']->id;
        $this->assertRecipients([
            $this->a['user']->id, $secondBranch['user']->id, $this->sharedParent->user_id, $this->coParent->user_id,
        ], $filters);
        $this->confirm($this->previewId($filters))->assertRedirect();
        $this->work();

        $this->assertDatabaseCount('notifications', 4);
        $this->assertSame(0, $this->b['user']->notifications()->count());
        $this->assertSame(0, $this->c['user']->notifications()->count());
        $this->assertSame(0, $this->unlinked->notifications()->count());
        $this->assertStringNotContainsString($this->b['user']->name, $this->body($this->sharedParent->user));
    }

    public function test_edit_keeps_all_explicit_multi_grade_branch_ids_without_broadening_older_grade_or_all_modes(): void
    {
        $secondBranch = $this->addSecondGrade();
        $selected = [$this->a['classroom']->id, $secondBranch['classroom']->id];
        foreach (['branches', 'grade', 'all'] as $oldMode) {
            $filters = ['audience' => 'families', 'selection_mode' => $oldMode, 'classroom_ids' => $selected];
            $response = $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data($filters) + ['edit' => 1]);
            $response->assertOk()->assertViewHas('draftMessage', self::MESSAGE);
            $initial = $response->viewData('messagingData')['initialState'];
            $this->assertSame('branches', $initial['mode']);
            $this->assertSame($selected, $initial['branchIds']);
            $this->assertSame([], $initial['personIds']);
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_editing_an_all_selection_does_not_add_a_newly_assigned_existing_class(): void
    {
        $filters = $this->group(['a', 'b', 'c'], 'families', 'all');
        $this->previewId($filters);
        $subject = $this->createSubjectFor($this->foreign['classroom'], 'MESSAGE-NEW-ASSIGNMENT');
        $this->teacher->classrooms()->attach($this->foreign['classroom']->id, ['subject_id' => $subject->id]);

        $response = $this->post('/teacher/notifications/preview', $this->data($filters) + ['edit' => 1])->assertOk();
        $data = $response->viewData('messagingData');
        $this->assertContains($this->foreign['classroom']->id, array_column($data['classes'], 'id'));
        $this->assertSame('branches', $data['initialState']['mode']);
        $this->assertSame($filters['classroom_ids'], $data['initialState']['branchIds']);
        $this->assertNotContains($this->foreign['classroom']->id, $data['initialState']['branchIds']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_an_unbranched_grade_uses_its_one_existing_class_without_creating_or_relabeling_classes(): void
    {
        $this->c['classroom']->update(['name' => 'الثالث ثانوي', 'stage' => 'ثانوي', 'section' => '']);
        $before = DB::table('classrooms')->orderBy('id')->get()->toJson();
        $response = $this->actingAs($this->actor)->get('/teacher/notifications')->assertOk();
        $data = $response->viewData('messagingData');
        $room = collect($data['classes'])->firstWhere('id', $this->c['classroom']->id);
        $grade = collect($data['grades'])->first(fn ($grade) => in_array($this->c['classroom']->id, $grade['classIds'], true));
        $this->assertSame('', $room['branch']);
        $this->assertSame([$this->c['classroom']->id], $grade['classIds']);
        $this->assertSame('الثالث ثانوي', $room['name']);
        $filters = $this->group(['c'], 'families', 'branches');
        $this->assertRecipients([$this->c['user']->id, $this->unlinked->id, $this->cParent->user_id], $filters);
        $this->previewId($filters);
        $this->assertSame($before, DB::table('classrooms')->orderBy('id')->get()->toJson());
    }

    public function test_multiple_branches_exclude_unselected_students_and_their_parent_context(): void
    {
        $filters = $this->group(['a', 'c'], 'families', 'branches');
        $this->assertRecipients([
            $this->a['user']->id, $this->c['user']->id, $this->unlinked->id,
            $this->sharedParent->user_id, $this->coParent->user_id, $this->cParent->user_id,
        ], $filters);
        $id = $this->previewId($filters);
        $this->confirm($id)->assertRedirect();
        $this->work();

        $body = $this->body($this->sharedParent->user);
        $this->assertStringContainsString('بخصوص:', $body);
        $this->assertStringContainsString($this->a['user']->name, $body);
        $this->assertStringNotContainsString($this->b['user']->name, $body);
        $this->assertStringNotContainsString($this->foreign['user']->name, $body);
        $this->assertSame(self::MESSAGE, $this->body($this->a['user']));
        $this->assertSame(0, $this->b['user']->notifications()->count());
        $this->assertSame(0, $this->foreignParent->user->notifications()->count());
        $this->assertDatabaseCount('notifications', 6);
    }

    public function test_selected_siblings_add_linked_parents_once_and_deliver_the_same_child_context_to_existing_push_transport(): void
    {
        $filters = $this->people([$this->a['user']->id, $this->b['user']->id]);
        $this->assertRecipients([
            $this->a['user']->id, $this->b['user']->id, $this->sharedParent->user_id, $this->coParent->user_id,
        ], $filters);
        $id = $this->previewId($filters);
        $this->confirm($id)->assertRedirect();
        $this->work();

        $record = $this->sharedParent->user->notifications()->sole();
        $this->assertStringContainsString($this->a['user']->name, $record->data['body']);
        $this->assertStringContainsString($this->b['user']->name, $record->data['body']);
        $this->assertStringNotContainsString($this->foreign['user']->name, $record->data['body']);
        $this->assertStringEndsWith("\n\n".self::MESSAGE, $record->data['body']);
        $this->assertSame('teacher_notification', $record->data['category']);
        $this->assertSame($record->id, $record->data['teacher_message_id']);
        $this->assertSame('/parent/messages?thread='.hash('sha256', 'messenger:teacher:'.$this->actor->id).'&student='.$this->a['student']->id, $record->data['url']);
        $push = collect($this->pushes)->where('user_id', $this->sharedParent->user_id)->sole()['payload'];
        $this->assertSame($record->data['body'], $push['body']);
        $this->assertSame($record->data['title'], $push['title']);
        $this->assertSame($record->id, $push['notification_id']);
        $this->assertSame('teacher_notification', $push['category']);
        $this->assertCount(4, $this->pushes);
        $this->assertSame(0, $this->cParent->user->notifications()->count());
        $this->get(route('teacher.notifications.show', $id))->assertOk()->assertViewHas('stored', 4)->assertViewHas('accepted', 4);
    }

    public function test_selected_unrelated_students_include_only_their_own_linked_parents(): void
    {
        $this->assertRecipients([
            $this->a['user']->id, $this->c['user']->id,
            $this->sharedParent->user_id, $this->coParent->user_id, $this->cParent->user_id,
        ], $this->people([$this->a['user']->id, $this->c['user']->id]));
    }

    public function test_individual_parent_selection_does_not_include_students_coguardians_or_other_families(): void
    {
        $filters = $this->people([$this->sharedParent->user_id], 'parents');
        $this->assertRecipients([$this->sharedParent->user_id], $filters);
        $this->confirm($this->previewId($filters))->assertRedirect();
        $this->work();

        $body = $this->body($this->sharedParent->user);
        $this->assertStringContainsString($this->a['user']->name, $body);
        $this->assertStringContainsString($this->b['user']->name, $body);
        $this->assertStringNotContainsString($this->foreign['user']->name, $body);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, $this->a['user']->notifications()->count());
        $this->assertSame(0, $this->coParent->user->notifications()->count());
    }

    public function test_multiple_parent_selection_remains_parent_only_with_each_familys_context(): void
    {
        $filters = $this->people([$this->sharedParent->user_id, $this->cParent->user_id], 'parents');
        $this->assertRecipients([$this->sharedParent->user_id, $this->cParent->user_id], $filters);
        $this->confirm($this->previewId($filters))->assertRedirect();
        $this->work();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertStringContainsString($this->c['user']->name, $this->body($this->cParent->user));
        $this->assertStringNotContainsString($this->a['user']->name, $this->body($this->cParent->user));
        $this->assertStringNotContainsString($this->c['user']->name, $this->body($this->sharedParent->user));
    }

    public function test_parent_only_group_deduplicates_shared_parents_and_limits_context_to_selected_branches(): void
    {
        $filters = $this->group(['a', 'c'], 'parents', 'branches');
        $this->assertRecipients([$this->sharedParent->user_id, $this->coParent->user_id, $this->cParent->user_id], $filters);
        $this->confirm($this->previewId($filters))->assertRedirect();
        $this->work();
        $this->assertDatabaseCount('notifications', 3);
        $this->assertStringNotContainsString($this->b['user']->name, $this->body($this->sharedParent->user));
        $this->assertSame(0, $this->a['user']->notifications()->count());
    }

    public function test_student_without_a_linked_parent_still_receives_the_message(): void
    {
        $filters = $this->people([$this->unlinked->id]);
        $this->assertRecipients([$this->unlinked->id], $filters);
        $this->confirm($this->previewId($filters))->assertRedirect();
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(self::MESSAGE, $this->body($this->unlinked));
        $record = $this->unlinked->notifications()->sole();
        $this->assertSame($record->id, $record->data['teacher_message_id']);
        $this->assertSame('/student/messages?thread='.hash('sha256', 'messenger:teacher:'.$this->actor->id), $record->data['url']);
    }

    public function test_tampered_outsider_ids_and_mixed_or_empty_selections_are_rejected_before_queueing(): void
    {
        $group = $this->group(['a']);
        $student = $this->people([$this->a['user']->id]);
        $parent = $this->people([$this->sharedParent->user_id], 'parents');
        $invalid = [
            array_replace($group, ['classroom_ids' => [$this->a['classroom']->id, $this->foreign['classroom']->id]]),
            $this->people([$this->a['user']->id, $this->foreign['user']->id]),
            $this->people([$this->sharedParent->user_id, $this->foreignParent->user_id], 'parents'),
            $this->people([$this->sharedParent->user_id]),
            $this->people([$this->a['user']->id], 'parents'),
            $this->people([$this->actor->id]),
            array_replace($group, ['classroom_ids' => []]),
            $this->people([]),
            $this->people([], 'parents'),
            $group + ['student_ids' => [$this->a['user']->id]],
            $student + ['classroom_ids' => [$this->a['classroom']->id]],
            $parent + ['student_ids' => [$this->a['user']->id]],
            $group + ['student_ids' => []],
            array_replace($group, ['classroom_ids' => 'all']),
            array_replace($group, ['classroom_ids' => [[$this->a['classroom']->id]]]),
            array_replace($group, ['selection_mode' => 'unexpected']),
        ];
        foreach ($invalid as $filters) {
            $this->actingAs($this->actor)->postJson('/teacher/notifications/preview', $this->data($filters))->assertUnprocessable();
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], $this->pushes);
    }

    public function test_classroom_array_preserves_exact_subject_assignment_pair_checks(): void
    {
        $this->b['classroom']->subjects()->attach($this->subjects['a']);
        $filters = $this->group(['a', 'b']) + ['subject_id' => $this->subjects['a']->id];
        $this->actingAs($this->actor)->postJson('/teacher/notifications/preview', $this->data($filters))->assertUnprocessable();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_invalid_recipient_preview_redirects_to_the_get_composer_and_preserves_the_draft(): void
    {
        $response = $this->actingAs($this->actor)->from('/teacher/notifications/preview')
            ->post('/teacher/notifications/preview', $this->data($this->people([
                $this->a['user']->id, $this->foreign['user']->id,
            ])));

        $response->assertRedirect(route('teacher.notifications.index'))->assertSessionHasErrors();
        $this->get($response->headers->get('Location'))->assertOk()->assertViewIs('teacher.notifications.create')
            ->assertViewHas('draftTitle', 'رسالة المعلم')->assertViewHas('draftMessage', self::MESSAGE);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_empty_and_malformed_arrays_return_to_a_working_composer_instead_of_the_post_only_preview_url(): void
    {
        $invalid = [
            array_replace($this->group(), ['classroom_ids' => []]),
            array_replace($this->group(), ['classroom_ids' => 'all']),
            $this->people([]),
            $this->people([[$this->a['user']->id]]),
            array_replace($this->people([], 'parents'), ['guardian_ids' => 'invalid']),
        ];
        foreach ($invalid as $filters) {
            $response = $this->actingAs($this->actor)->from('/teacher/notifications/preview')
                ->post('/teacher/notifications/preview', $this->data($filters));
            $response->assertRedirect(route('teacher.notifications.index'))->assertSessionHasErrors();
            $this->get($response->headers->get('Location'))->assertOk()->assertViewIs('teacher.notifications.create')
                ->assertViewHas('draftTitle', 'رسالة المعلم')->assertViewHas('draftMessage', self::MESSAGE);
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_native_form_defaults_without_a_selected_array_fail_closed_instead_of_targeting_all_students(): void
    {
        $response = $this->actingAs($this->actor)->from('/teacher/notifications/preview')
            ->post('/teacher/notifications/preview', $this->data([
                'audience' => 'families', 'selection_mode' => 'branches',
            ]));
        $response->assertRedirect(route('teacher.notifications.index'))->assertSessionHasErrors();
        $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('draftMessage', self::MESSAGE);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('job_batches', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], $this->pushes);
    }

    public function test_stale_confirmation_returns_to_the_get_composer_with_the_owned_preview_draft_and_selection(): void
    {
        $filters = $this->people([$this->a['user']->id, $this->b['user']->id]);
        $id = $this->previewId($filters);
        $this->sharedParent->students()->detach($this->a['student']);

        $response = $this->from('/teacher/notifications/preview')->confirm($id);
        $response->assertRedirect(route('teacher.notifications.index'))->assertSessionHasErrors('preview_id');
        $composer = $this->get($response->headers->get('Location'))->assertOk()
            ->assertViewIs('teacher.notifications.create')->assertViewHas('draftTitle', 'رسالة المعلم')
            ->assertViewHas('draftMessage', self::MESSAGE);
        $initial = $composer->viewData('messagingData')['initialState'];
        $this->assertSame('families', $initial['audience']);
        $this->assertSame('people', $initial['mode']);
        $this->assertSame($filters['student_ids'], $initial['personIds']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failed_confirmation_never_restores_another_teachers_cached_message_or_recipients(): void
    {
        $id = $this->previewId($this->people([$this->a['user']->id]));
        $other = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $other->id, 'status' => 'active']);

        $response = $this->actingAs($other)->from('/teacher/notifications/preview')->confirm($id);
        $response->assertRedirect(route('teacher.notifications.index'))->assertSessionHasErrors('preview_id');
        $composer = $this->get($response->headers->get('Location'))->assertOk()
            ->assertViewHas('draftTitle', '')->assertViewHas('draftMessage', '')
            ->assertDontSee(self::MESSAGE)->assertDontSee($this->a['user']->name);
        $this->assertSame([], $composer->viewData('messagingData')['initialState']['personIds']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_changed_parent_child_link_invalidates_confirmation_even_when_recipient_ids_are_unchanged(): void
    {
        $filters = $this->people([$this->a['user']->id, $this->b['user']->id]);
        $before = $this->recipientIds($filters);
        $id = $this->previewId($filters);
        $this->sharedParent->students()->detach($this->a['student']);
        $this->assertSame($before, $this->recipientIds($filters));

        $this->confirm($id)->assertSessionHasErrors('preview_id');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_changed_child_name_requires_a_new_parent_context_preview(): void
    {
        $id = $this->previewId($this->people([$this->sharedParent->user_id], 'parents'));
        $this->a['user']->update(['name' => 'اسم الطالب بعد التصحيح']);
        $this->confirm($id)->assertSessionHasErrors('preview_id');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_worker_skips_push_when_parent_context_changes_after_inbox_delivery(): void
    {
        $id = $this->previewId($this->people([$this->sharedParent->user_id], 'parents'));
        $this->confirm($id)->assertRedirect();
        $this->sharedParent->students()->detach($this->a['student']);
        $this->work();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame([], $this->pushes);
        $this->get(route('teacher.notifications.show', $id))->assertOk()->assertViewHas('stored', 1)->assertViewHas('skipped', 0);
    }

    public function test_new_guardian_after_confirmation_is_not_added_to_the_queued_recipient_snapshot(): void
    {
        $id = $this->previewId($this->people([$this->a['user']->id]));
        $this->confirm($id)->assertRedirect();
        $newParent = $this->parentFor('ولي أضيف بعد التأكيد', [$this->a['student']]);
        $this->work();

        $this->assertSame(0, $newParent->user->notifications()->count());
        $this->assertSame(1, $this->sharedParent->user->notifications()->count());
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_confirmation_replay_and_delivery_retry_preserve_one_notification_and_push_per_recipient(): void
    {
        $id = $this->previewId($this->people([$this->sharedParent->user_id], 'parents'));
        $this->confirm($id)->assertRedirect();
        $this->confirm($id)->assertRedirect();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('job_batches', 1);
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::MESSAGE, $payload['data']['command']);
        // Re-run the exact trusted job produced by this test, including its frozen context.
        $retry = unserialize(app('encrypter')->decrypt($payload['data']['command']));
        $this->assertInstanceOf(SendTeacherNotification::class, $retry);
        $this->work();
        $this->app->call([$retry, 'handle']);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertCount(1, $this->pushes);
        $this->assertSame(1, DB::table('audit_logs')->where('module', 'teacher_notification_delivery')->count());
        $this->assertStringContainsString($this->a['user']->name, $this->body($this->sharedParent->user));
    }
}
