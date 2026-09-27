<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherLessonRequest;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Subject;
use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class LessonController extends Controller
{
    use Concerns\InteractsWithTeacherScope;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,scheduled,published,cancelled'],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'published_at' => ['nullable', 'date', 'date_format:Y-m-d'],
        ]);
        $classroomIds = $this->assignedClassroomIds($teacher);
        $lessons = Lesson::query()
            ->with(['subject', 'unit', 'attachments'])
            ->where('teacher_id', $teacher->id)
            ->when(! empty($filters['classroom_id']) && $classroomIds->contains((int) $filters['classroom_id']), fn ($query) => $query->where('classroom_id', $filters['classroom_id']))
            ->when(! empty($filters['published_at']), fn ($query) => $query->whereDate('published_at', $filters['published_at']))
            ->when(! empty($filters['status']), function ($query) use ($filters) {
                if ($filters['status'] === 'draft') {
                    $query->where('status', 'draft');
                } elseif ($filters['status'] === 'cancelled') {
                    $query->where('status', 'cancelled');
                } elseif ($filters['status'] === 'scheduled') {
                    $query->where('status', 'published')->where('published_at', '>', now());
                } else {
                    $query->where('status', 'published')->where('published_at', '<=', now());
                }
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $lessonSummary = ['draft' => 0, 'scheduled' => 0, 'published' => 0, 'cancelled' => 0];
        Lesson::where('teacher_id', $teacher->id)->get(['status', 'published_at'])->each(function ($lesson) use (&$lessonSummary) {
            if ($lesson->status === 'draft') {
                $lessonSummary['draft']++;
            } elseif ($lesson->status === 'cancelled') {
                $lessonSummary['cancelled']++;
            } elseif ($lesson->published_at?->isFuture()) {
                $lessonSummary['scheduled']++;
            } else {
                $lessonSummary['published']++;
            }
        });
        $lessonSummary = collect($lessonSummary);
        $classrooms = Classroom::whereIn('id', $classroomIds)->orderBy('name')->get();

        return view('teacher.lessons.index', compact('teacher', 'lessons', 'filters', 'lessonSummary', 'classrooms'));
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Lesson(['status' => 'draft']));
    }

    public function store(TeacherLessonRequest $request): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $data = $request->validated();
        $this->assertPair($teacher, $data);
        $lesson = Lesson::create($this->lessonData($data, $teacher->id));
        $this->storeAttachment($request, $lesson);
        AuditService::record('created', 'lessons', $lesson);

        return redirect()->route('teacher.lessons.index')->with('success', 'تم حفظ الدرس بنجاح.');
    }

    public function edit(Request $request, Lesson $lesson): View
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);

        return $this->form($request, $lesson->load('attachments'));
    }

    public function update(TeacherLessonRequest $request, Lesson $lesson): RedirectResponse
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);
        $data = $request->validated();
        abort_if(
            $data['status'] === 'draft'
            && $lesson->status === 'published'
            && $lesson->published_at?->isPast(),
            422,
            'لا يمكن حفظ الدرس المنشور كمسودة بعد موعد نشره.'
        );
        $this->assertPair($teacher, $data);
        $lesson->update($this->lessonData($data, $teacher->id));
        $this->storeAttachment($request, $lesson);
        AuditService::record('updated', 'lessons', $lesson);

        return redirect()->route('teacher.lessons.index')->with('success', 'تم تحديث الدرس بنجاح.');
    }

    public function publish(Request $request, Lesson $lesson): RedirectResponse
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);
        $lesson->update(['status' => 'published', 'published_at' => now()]);

        return back()->with('success', 'تم نشر الدرس.');
    }

    public function cancel(Request $request, Lesson $lesson): RedirectResponse
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);
        abort_unless($lesson->published_at?->isPast(), 422, 'لا يمكن إلغاء درس قبل موعد نشره.');
        $lesson->update(['status' => 'cancelled']);

        return back()->with('success', 'تم إلغاء الدرس وإيقاف مشاركته.');
    }

    public function destroy(Request $request, Lesson $lesson): RedirectResponse
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);
        $lesson->load('attachments');
        foreach ($lesson->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->file_path);
        }
        $lesson->delete();

        return back()->with('success', 'تم حذف الدرس.');
    }

    private function form(Request $request, Lesson $lesson): View
    {
        $teacher = $this->teacher($request);
        $pairs = $this->assignmentPairs($teacher);
        $classrooms = Classroom::whereIn('id', $pairs->pluck('classroom_id'))->get();
        $subjects = Subject::whereIn('id', $pairs->pluck('subject_id'))->get();
        return view('teacher.lessons.form', compact('lesson', 'classrooms', 'subjects', 'pairs'));
    }

    private function assertPair($teacher, array $data): void
    {
        abort_unless($this->ownsPair($teacher, (int) $data['classroom_id'], (int) $data['subject_id']), 403);
    }

    private function lessonData(array $data, int $teacherId): array
    {
        $publishedAt = ! empty($data['published_at']) ? \Illuminate\Support\Carbon::parse($data['published_at']) : null;

        $unit = ! empty(trim($data['unit_title'] ?? ''))
            ? Unit::firstOrCreate(['subject_id' => $data['subject_id'], 'title' => trim($data['unit_title'])], ['position' => 0])
            : null;

        return [
            'teacher_id' => $teacherId,
            'classroom_id' => $data['classroom_id'],
            'subject_id' => $data['subject_id'],
            'unit_id' => $unit?->id,
            'title' => $data['title'],
            'content' => $data['content'] ?? null,
            'status' => $data['status'],
            'published_at' => $data['status'] === 'published' ? (($publishedAt && $publishedAt->isFuture() && ! $publishedAt->isToday()) ? $publishedAt : now()) : null,
        ];
    }

    private function storeAttachment(TeacherLessonRequest $request, Lesson $lesson): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("lesson-attachments/{$lesson->id}", 'local');
            $lesson->attachments()->create([
                'title' => $file->getClientOriginalName(),
                'disk' => 'local',
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }
}
