<?php

namespace App\Http\Controllers\StudentPortal;

use App\Http\Controllers\Controller;
use App\Models\LibraryResource;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EducationController extends Controller
{
    public function subjects(Request $request): View
    {
        $student = $this->student($request);
        $subjects = $this->subjectsFor($student);

        return view('student.subjects.index', compact('student', 'subjects'));
    }

    public function subject(Request $request, Subject $subject): View
    {
        $student = $this->student($request);
        $subject = $this->subjectFor($student, $subject->id);

        $subject->load([
            'units' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'units.lessons' => fn ($query) => $query
                ->where('subject_id', $subject->id)
                ->where(fn ($query) => $query->where('classroom_id', $student->classroom_id)->orWhereNull('classroom_id'))
                ->published()
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        $unassignedLessons = $subject->lessons()
            ->published()
            ->where(fn ($query) => $query->where('classroom_id', $student->classroom_id)->orWhereNull('classroom_id'))
            ->whereNull('unit_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $resources = LibraryResource::query()
            ->visibleTo($student)
            ->where('subject_id', $subject->id)
            ->orderBy('title')
            ->get();

        return view('student.subjects.show', compact('student', 'subject', 'unassignedLessons', 'resources'));
    }

    public function lessons(Request $request, Subject $subject): View
    {
        $student = $this->student($request);
        $subject = $this->subjectFor($student, $subject->id);

        $subject->load([
            'units' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'units.lessons' => fn ($query) => $query
                ->where('subject_id', $subject->id)
                ->where(fn ($query) => $query->where('classroom_id', $student->classroom_id)->orWhereNull('classroom_id'))
                ->published()
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        $unassignedLessons = $subject->lessons()
            ->published()
            ->where(fn ($query) => $query->where('classroom_id', $student->classroom_id)->orWhereNull('classroom_id'))
            ->whereNull('unit_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return view('student.lessons.index', compact('student', 'subject', 'unassignedLessons'));
    }

    public function lesson(Request $request, Subject $subject, int $lesson): View
    {
        $student = $this->student($request);
        $subject = $this->subjectFor($student, $subject->id);
        $lesson = $subject->lessons()
            ->published()
            ->whereKey($lesson)
            ->where(fn ($query) => $query->where('classroom_id', $student->classroom_id)->orWhereNull('classroom_id'))
            ->where(function ($query) use ($subject): void {
                $query
                    ->whereNull('unit_id')
                    ->orWhereHas('unit', fn ($units) => $units->where('subject_id', $subject->id));
            })
            ->with(['unit', 'attachments'])
            ->firstOrFail();

        $resources = LibraryResource::query()
            ->visibleTo($student)
            ->where('subject_id', $subject->id)
            ->orderBy('title')
            ->get();

        return view('student.lessons.show', compact('student', 'subject', 'lesson', 'resources'));
    }

    public function library(Request $request): View
    {
        $student = $this->student($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'stage' => ['nullable', 'string', 'max:100'],
        ]);

        $subjects = $this->subjectsFor($student);
        $visibleResources = LibraryResource::query()->visibleTo($student);
        $categories = (clone $visibleResources)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
        $stages = $subjects->pluck('stage')
            ->push($student->classroom?->stage)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $resources = $visibleResources
            ->with(['subject', 'classroom'])
            ->when($filters['q'] ?? null, function ($query, string $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('category', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['subject_id'] ?? null, fn ($query, $subjectId) => $query->where('subject_id', $subjectId))
            ->when($filters['stage'] ?? null, function ($query, string $stage): void {
                $query->where(function ($stageQuery) use ($stage): void {
                    $stageQuery
                        ->whereHas('subject', fn ($subjects) => $subjects->where('stage', $stage))
                        ->orWhereHas('classroom', fn ($classrooms) => $classrooms->where('stage', $stage));
                });
            })
            ->orderBy('title')
            ->simplePaginate(12)
            ->withQueryString();

        return view('student.library.index', compact('student', 'resources', 'subjects', 'categories', 'stages', 'filters'));
    }

    public function downloadResource(Request $request, int $resource): StreamedResponse
    {
        $student = $this->student($request);
        $resource = LibraryResource::query()
            ->visibleTo($student)
            ->whereKey($resource)
            ->firstOrFail();

        return $this->download($resource->disk ?: 'public', $resource->file_path, $resource->title);
    }

    public function downloadAttachment(Request $request, Subject $subject, int $lesson, int $attachment): StreamedResponse
    {
        $student = $this->student($request);
        $subject = $this->subjectFor($student, $subject->id);
        $lesson = $subject->lessons()
            ->published()
            ->whereKey($lesson)
            ->where(function ($query) use ($subject): void {
                $query
                    ->whereNull('unit_id')
                    ->orWhereHas('unit', fn ($units) => $units->where('subject_id', $subject->id));
            })
            ->firstOrFail();
        $attachment = $lesson->attachments()->whereKey($attachment)->firstOrFail();

        return $this->download($attachment->disk, $attachment->file_path, $attachment->original_name ?: $attachment->title);
    }

    private function subjectsFor(Student $student)
    {
        if (! $student->classroom_id) {
            return collect();
        }

        return Subject::query()
            ->where('status', 'active')
            ->whereHas('classrooms', fn ($query) => $query->whereKey($student->classroom_id))
            ->orderBy('name')
            ->get();
    }

    private function subjectFor(Student $student, int $subjectId): Subject
    {
        abort_unless($student->classroom_id, 404);

        return Subject::query()
            ->whereKey($subjectId)
            ->where('status', 'active')
            ->whereHas('classrooms', fn ($query) => $query->whereKey($student->classroom_id))
            ->firstOrFail();
    }

    private function student(Request $request): Student
    {
        return $request->user()
            ->student()
            ->with(['user', 'classroom.academicYear'])
            ->firstOrFail();
    }

    private function download(string $disk, string $path, string $name): StreamedResponse
    {
        abort_unless(in_array($disk, ['local', 'public'], true), 404);

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        $safeName = trim((string) preg_replace('/[^\pL\pN._ -]+/u', '_', $name), ' ._');
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($safeName === '') {
            $safeName = 'resource';
        }

        if ($extension !== '' && pathinfo($safeName, PATHINFO_EXTENSION) === '') {
            $safeName .= '.'.$extension;
        }

        return $storage->download($path, $safeName, ['X-Content-Type-Options' => 'nosniff']);
    }
}
