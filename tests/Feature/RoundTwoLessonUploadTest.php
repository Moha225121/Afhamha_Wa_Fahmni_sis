<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class RoundTwoLessonUploadTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private array $fixture;
    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->fixture = $this->createStudentFixture('R2-UPLOAD');
        $this->fixture['subject'] = $this->createSubjectFor($this->fixture['classroom'], 'R2-UPLOAD');
        $this->teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id,
            'classroom_id' => $this->fixture['classroom']->id, 'subject_id' => $this->fixture['subject']->id]);
    }

    private function data(?string $key = null): array
    {
        return ['title' => 'درس رفع اصطناعي', 'content' => '<p>محتوى محفوظ</p>', 'status' => 'published',
            'classroom_id' => $this->fixture['classroom']->id, 'subject_id' => $this->fixture['subject']->id,
            'submission_key' => $key ?? (string) Str::uuid(),
            'attachments' => [UploadedFile::fake()->createWithContent('answer.pdf', "%PDF-1.4\nsynthetic attachment")]];
    }

    public function test_create_and_update_replay_keep_one_copy_of_each_attachment_after_lost_response(): void
    {
        $key = (string) Str::uuid();
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->teacher->user)->postJson(route('teacher.lessons.store'), $this->data($key))
                ->assertOk()->assertJsonPath('redirect', route('teacher.lessons.index'));
        }
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('lesson_attachments', 1);
        $lesson = Lesson::firstOrFail();
        $editKey = (string) Str::uuid();
        for ($i = 0; $i < 2; $i++) {
            $this->putJson(route('teacher.lessons.update', $lesson), $this->data($editKey))->assertOk();
        }
        $this->assertDatabaseCount('lesson_attachments', 2);
        $this->assertDatabaseCount('lesson_save_requests', 1);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $attachment = $lesson->attachments()->firstOrFail();
        $this->actingAs($this->fixture['user'])->get(route('student.lessons.attachments.download', [$this->fixture['subject'], $lesson, $attachment]))
            ->assertOk()->assertStreamedContent("%PDF-1.4\nsynthetic attachment");
        $outside = $this->createStudentFixture('R2-OUTSIDE');
        $this->actingAs($outside['user'])->get(route('student.lessons.attachments.download', [$this->fixture['subject'], $lesson, $attachment]))->assertNotFound();
    }

    public function test_validation_failure_does_not_consume_retry_key_and_unrelated_teacher_cannot_replay(): void
    {
        $this->actingAs($this->teacher->user)->postJson(route('teacher.lessons.store'), $this->data())->assertOk();
        $lesson = Lesson::firstOrFail();
        $key = (string) Str::uuid();
        $invalid = $this->data($key);
        $invalid['title'] = '';
        $this->putJson(route('teacher.lessons.update', $lesson), $invalid)->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('lesson_save_requests', 0);
        $this->putJson(route('teacher.lessons.update', $lesson), $this->data($key))->assertOk();
        $other = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->actingAs($other->user)->putJson(route('teacher.lessons.update', $lesson), $this->data($key))->assertNotFound();
        $this->assertDatabaseCount('lesson_attachments', 2);
    }

    public function test_save_failure_rolls_back_lesson_receipt_and_new_files_without_removing_existing_files(): void
    {
        $this->actingAs($this->teacher->user)->postJson(route('teacher.lessons.store'), $this->data())->assertOk();
        $lesson = Lesson::firstOrFail();
        $existing = Storage::disk('local')->allFiles();
        $key = (string) Str::uuid();
        // Inject the failure at a real persistence boundary after the file is stored.
        \App\Models\LessonAttachment::creating(function (): void { throw new \RuntimeException('Synthetic attachment persistence failure'); });
        try {
            $this->putJson(route('teacher.lessons.update', $lesson), $this->data($key))->assertServerError();
            $this->assertDatabaseCount('lesson_save_requests', 0);
            $this->assertDatabaseCount('lesson_attachments', 1);
            $this->assertSame($existing, Storage::disk('local')->allFiles());
        } finally {
            \App\Models\LessonAttachment::flushEventListeners();
        }
        $this->putJson(route('teacher.lessons.update', $lesson), $this->data($key))->assertOk();
        $this->assertDatabaseCount('lesson_attachments', 2);
    }

    public function test_post_commit_exception_does_not_delete_saved_create_or_update_attachments_or_claim_failure(): void
    {
        $key = (string) Str::uuid();
        $events = \Illuminate\Support\Facades\Event::getFacadeRoot();
        $events->listen(\Illuminate\Database\Events\TransactionCommitted::class, function (): void {
            throw new \RuntimeException('Synthetic failure after database commit');
        });
        try {
            $this->actingAs($this->teacher->user)->postJson(route('teacher.lessons.store'), $this->data($key))->assertOk();
        } finally {
            $events->forget(\Illuminate\Database\Events\TransactionCommitted::class);
        }
        $lesson = Lesson::firstOrFail();
        $this->assertDatabaseCount('lesson_attachments', 1);
        Storage::disk('local')->assertExists($lesson->attachments()->firstOrFail()->file_path);
        $editKey = (string) Str::uuid();
        $events->listen(\Illuminate\Database\Events\TransactionCommitted::class, function (): void {
            throw new \RuntimeException('Synthetic failure after update commit');
        });
        try {
            $this->putJson(route('teacher.lessons.update', $lesson), $this->data($editKey))->assertOk();
        } finally {
            $events->forget(\Illuminate\Database\Events\TransactionCommitted::class);
        }
        $this->putJson(route('teacher.lessons.update', $lesson), $this->data($editKey))->assertOk();
        $this->assertDatabaseCount('lesson_attachments', 2);
        $this->assertDatabaseCount('lesson_save_requests', 1);
        foreach ($lesson->attachments()->get() as $attachment) Storage::disk('local')->assertExists($attachment->file_path);
    }
}
