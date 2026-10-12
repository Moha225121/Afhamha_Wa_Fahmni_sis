<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttachment;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\LibraryResource;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherPortalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_remove_only_their_avatar_reference_and_unshared_file(): void
    {
        Storage::fake('public');
        $teacherUser = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
            'avatar_path' => 'teacher-avatars/shared.png',
        ]);
        Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $otherUser = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
            'avatar_path' => 'teacher-avatars/shared.png',
        ]);
        Storage::disk('public')->put('teacher-avatars/shared.png', 'shared image');

        $this->actingAs($teacherUser)
            ->get(route('teacher.profile.edit'))
            ->assertOk()
            ->assertSee('name="remove_avatar"', false);
        $this->actingAs($teacherUser)
            ->put(route('teacher.profile.update'), [
                'name' => $teacherUser->name,
                'remove_avatar' => '1',
            ])
            ->assertRedirect();

        $this->assertNull($teacherUser->fresh()->avatar_path);
        $this->assertSame('teacher-avatars/shared.png', $otherUser->fresh()->avatar_path);
        Storage::disk('public')->assertExists('teacher-avatars/shared.png');

        $teacherUser->update(['avatar_path' => 'teacher-avatars/unique.png']);
        Storage::disk('public')->put('teacher-avatars/unique.png', 'private to this user');
        $this->put(route('teacher.profile.update'), [
            'name' => $teacherUser->name,
            'remove_avatar' => '1',
        ])->assertRedirect();

        $this->assertNull($teacherUser->fresh()->avatar_path);
        Storage::disk('public')->assertMissing('teacher-avatars/unique.png');
    }

    public function test_assigned_teacher_can_review_and_grade_another_teachers_assignment(): void
    {
        Storage::fake('local');
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'Grade 7', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'Biology', 'code' => 'BIO-7', 'stage' => 'Basic', 'status' => 'active']);
        $classroom->subjects()->attach($subject);

        $ownerUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $owner = Teacher::create(['user_id' => $ownerUser->id, 'status' => 'active']);
        $reviewerUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $reviewer = Teacher::create(['user_id' => $reviewerUser->id, 'status' => 'active']);
        foreach ([$owner, $reviewer] as $teacher) {
            DB::table('teacher_assignments')->insert([
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
            ]);
        }

        $studentUser = User::factory()->create(['name' => 'Student Review', 'role' => 'student', 'status' => 'active']);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'S-REVIEW',
            'classroom_id' => $classroom->id,
            'status' => 'active',
        ]);
        $assignment = Assignment::create([
            'title' => 'Biology Worksheet',
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'teacher_id' => $owner->id,
            'instructions' => 'Submit the worksheet.',
            'due_at' => now()->addDay(),
            'due_date' => now()->addDay()->toDateString(),
            'max_score' => 10,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'status' => 'submitted',
            'submitted_at' => now(),
            'notes' => 'My answer is attached.',
        ]);
        $path = "assignment-submissions/{$assignment->id}/{$student->id}/answer.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\nanswer");
        AssignmentSubmissionAttachment::create([
            'assignment_submission_id' => $submission->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'answer.pdf',
            'mime_type' => 'application/pdf',
            'size' => Storage::disk('local')->size($path),
        ]);

        $this->actingAs($reviewerUser)
            ->get(route('teacher.assignments.show', $assignment))
            ->assertOk()
            ->assertSeeText('Student Review')
            ->assertSeeText('My answer is attached.')
            ->assertSeeText('answer.pdf')
            ->assertSee(route('teacher.submissions.file', $submission), false);
        $this->post(route('teacher.assignments.submissions.store', $assignment), [
            'submitted' => [$student->id => '1'],
            'scores' => [$student->id => '8'],
        ])->assertRedirect();

        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission->id,
            'score' => 8,
            'graded_by' => $reviewerUser->id,
        ]);
    }

    public function test_repeated_lesson_create_request_with_same_token_is_idempotent(): void
    {
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'Grade 8', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'Chemistry', 'code' => 'CHEM-8', 'stage' => 'Basic', 'status' => 'active']);
        $classroom->subjects()->attach($subject);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);
        $payload = [
            'submission_key' => (string) Str::uuid(),
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'title' => 'Atomic Structure',
            'content' => 'Lesson content',
            'status' => 'draft',
        ];

        $this->actingAs($teacherUser)->post(route('teacher.lessons.store'), $payload)->assertRedirect();
        $this->post(route('teacher.lessons.store'), $payload)->assertRedirect();

        $this->assertSame(1, Lesson::where('submission_key', $payload['submission_key'])->count());
    }

    public function test_lesson_camera_image_upload_persists_on_create_and_edit(): void
    {
        Storage::fake('local');
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'Grade 9', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'Earth Science', 'code' => 'EARTH-9', 'stage' => 'Basic', 'status' => 'active']);
        $classroom->subjects()->attach($subject);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);
        $payload = [
            'submission_key' => (string) Str::uuid(),
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'title' => 'Rock Cycle',
            'content' => 'Lesson content',
            'status' => 'draft',
            'attachments' => [UploadedFile::fake()->image('camera.jpg')],
        ];

        $this->actingAs($teacherUser)->post(route('teacher.lessons.store'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $lesson = Lesson::where('submission_key', $payload['submission_key'])->firstOrFail();
        $firstPath = $lesson->attachments()->firstOrFail()->file_path;
        Storage::disk('local')->assertExists($firstPath);

        $update = array_merge($payload, [
            'attachments' => [UploadedFile::fake()->create('edit-camera.heic', 10, 'image/heic')],
        ]);
        unset($update['submission_key']);
        $this->put(route('teacher.lessons.update', $lesson), $update)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(2, $lesson->fresh()->attachments);
        Storage::disk('local')->assertExists($firstPath);

        $invalid = array_merge($update, ['attachments' => [UploadedFile::fake()->create('unsafe.exe', 5, 'application/x-msdownload')]]);
        $this->put(route('teacher.lessons.update', $lesson), $invalid)->assertSessionHasErrors('attachments.0');
        $this->assertCount(2, $lesson->fresh()->attachments);

        $overRequestLimit = array_merge($update, ['attachments' => [
            UploadedFile::fake()->create('first.pdf', 12000, 'application/pdf'),
            UploadedFile::fake()->create('second.pdf', 11000, 'application/pdf'),
        ]]);
        $this->put(route('teacher.lessons.update', $lesson), $overRequestLimit)->assertSessionHasErrors('attachments');
        $this->assertCount(2, $lesson->fresh()->attachments);
    }

    public function test_lesson_form_filters_classes_by_assigned_subject_and_server_rejects_wrong_pair(): void
    {
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $biologyClass = Classroom::create(['name' => 'Grade 7', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $chemistryClass = Classroom::create(['name' => 'Grade 8', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $biology = Subject::create(['name' => 'Biology', 'code' => 'BIO-FILTER', 'stage' => 'Basic', 'status' => 'active']);
        $chemistry = Subject::create(['name' => 'Chemistry', 'code' => 'CHEM-FILTER', 'stage' => 'Basic', 'status' => 'active']);
        $biologyClass->subjects()->attach($biology);
        $chemistryClass->subjects()->attach($chemistry);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        foreach ([[$biologyClass, $biology], [$chemistryClass, $chemistry]] as [$classroom, $subject]) {
            DB::table('teacher_assignments')->insert([
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
            ]);
        }

        $this->actingAs($teacherUser)
            ->get(route('teacher.lessons.create'))
            ->assertOk()
            ->assertSee('data-subjects="'.$biology->id.'"', false)
            ->assertSee('data-subjects="'.$chemistry->id.'"', false);
        $this->post(route('teacher.lessons.store'), [
            'classroom_id' => $chemistryClass->id,
            'subject_id' => $biology->id,
            'title' => 'Invalid Biology Lesson',
            'content' => 'Not allowed for this class.',
            'status' => 'draft',
            'submission_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseMissing('lessons', ['title' => 'Invalid Biology Lesson']);
    }

    public function test_teacher_library_read_and_file_routes_enforce_assigned_resource_scope(): void
    {
        Storage::fake('local');
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'Grade 10', 'section' => 'A', 'stage' => 'Secondary', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'Physics', 'code' => 'PHY-10', 'stage' => 'Secondary', 'status' => 'active']);
        $classroom->subjects()->attach($subject);
        $assignedUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $assignedTeacher = Teacher::create(['user_id' => $assignedUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $assignedTeacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);
        $unassignedUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $unassignedUser->id, 'status' => 'active']);
        $path = 'library/physics-grade-10.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nphysics");
        $resource = LibraryResource::create([
            'title' => 'Physics Guide',
            'subject_id' => $subject->id,
            'classroom_id' => $classroom->id,
            'file_path' => $path,
            'disk' => 'local',
            'is_public' => false,
            'audience' => 'teachers',
            'status' => 'active',
            'created_by' => User::factory()->create(['role' => 'admin', 'status' => 'active'])->id,
        ]);

        $this->actingAs($assignedUser)
            ->get(route('teacher.library.read', $resource))
            ->assertOk()
            ->assertSeeText('Physics Guide');
        $this->get(route('teacher.library.file', $resource))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('teacher.library.download', $resource))
            ->assertOk()
            ->assertDownload();
        $this->actingAs($unassignedUser)->get(route('teacher.library.read', $resource))->assertNotFound();
        $this->get(route('teacher.library.file', $resource))->assertNotFound();
        $this->get(route('teacher.library.download', $resource))->assertNotFound();
    }
}
