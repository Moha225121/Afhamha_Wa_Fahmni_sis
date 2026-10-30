<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherLessonRequest;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Unit;
use App\Rules\LessonAttachmentSize;
use App\Services\AuditService;
use App\Services\LessonPublicationTime;
use App\Services\LessonUploadService;
use App\Services\LessonVideoValidator;
use App\Services\ParentNotificationService;
use App\Services\TeacherLessonTargets;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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
            ->with(['subject', 'unit', 'attachments', 'classroom', 'classrooms'])
            ->withCount('views')
            ->where('teacher_id', $teacher->id)
            ->when(! empty($filters['classroom_id']) && $classroomIds->contains((int) $filters['classroom_id']), fn ($query) => $query
                ->where(fn ($query) => $query->where('classroom_id', $filters['classroom_id'])
                    ->orWhereHas('classrooms', fn ($classrooms) => $classrooms->whereKey($filters['classroom_id']))))
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
        $publicationTimezone = LessonPublicationTime::timezone();

        return view('teacher.lessons.index', compact('teacher', 'lessons', 'filters', 'lessonSummary', 'classrooms', 'publicationTimezone'));
    }

    public function views(Request $request, Lesson $lesson): View
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $lesson->load([
            'subject',
            'classroom' => fn ($query) => $query->withTrashed(),
            'classrooms' => fn ($query) => $query->withTrashed(),
        ])->loadCount('views');
        $summary = ['viewed' => (int) $lesson->views_count];
        $viewers = $lesson->views()
            ->with([
                'student.user',
                'student.classroom' => fn ($query) => $query->withTrashed(),
            ])
            ->when($filters['q'] ?? null, fn ($query, $search) => $query
                ->whereHas('student', fn ($students) => $students
                    ->where(fn ($students) => $students
                        ->where('student_number', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($users) => $users->where('name', 'like', '%'.$search.'%')))))
            ->orderByDesc('last_viewed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
        $publicationTimezone = LessonPublicationTime::timezone();

        return view('teacher.lessons.views', compact('teacher', 'lesson', 'viewers', 'summary', 'filters', 'publicationTimezone'));
    }

    public function show(Request $request, Lesson $lesson): View
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);
        $lesson->load([
            'subject',
            'unit',
            'attachments',
            'classroom' => fn ($query) => $query->withTrashed(),
            'classrooms' => fn ($query) => $query->withTrashed(),
        ])->loadCount('views');
        $publicationTimezone = LessonPublicationTime::timezone();

        return view('teacher.lessons.show', compact('teacher', 'lesson', 'publicationTimezone'));
    }

    public function attachment(Request $request, Lesson $lesson, int $attachment): BinaryFileResponse
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);
        $attachment = $lesson->attachments()->whereKey($attachment)->firstOrFail();
        $disk = $attachment->disk;
        $path = $attachment->file_path;
        abort_unless(in_array($disk, ['local', 'public'], true) && is_string($path), 404);
        $normalizedPath = str_replace('\\', '/', $path);
        abort_unless($normalizedPath !== ''
            && $normalizedPath === $path
            && ! str_starts_with($normalizedPath, '/')
            && ! str_contains($normalizedPath, ':')
            && ! str_contains($normalizedPath, "\0")
            && ! preg_match('~(^|/)\.{1,2}(/|$)~', $normalizedPath), 404);

        $storage = Storage::disk($disk);
        $root = realpath($storage->path(''));
        $filePath = realpath($storage->path($path));
        abort_unless(is_string($root) && is_string($filePath) && is_file($filePath) && is_readable($filePath), 404);
        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $resolvedPath = str_replace('\\', '/', $filePath);
        if (PHP_OS_FAMILY === 'Windows') {
            $rootPrefix = strtolower($rootPrefix);
            $resolvedPath = strtolower($resolvedPath);
        }
        abort_unless(str_starts_with($resolvedPath, $rootPrefix), 404);

        $mimeType = File::mimeType($filePath) ?: 'application/octet-stream';
        $inline = ! $request->boolean('download') && in_array($mimeType, [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/bmp',
            'image/x-ms-bmp',
            'image/avif',
            'video/mp4',
        ], true);
        $name = $attachment->original_name ?: $attachment->title;
        $safeName = mb_substr(trim((string) preg_replace('/[^\pL\pN._ -]+/u', '_', $name), ' ._'), 0, 180);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : '';
        $fallbackName = 'lesson-attachment'.($extension !== '' ? '.'.$extension : '');
        if ($safeName === '') {
            $safeName = $fallbackName;
        } elseif ($extension !== '' && pathinfo($safeName, PATHINFO_EXTENSION) === '') {
            $safeName .= '.'.$extension;
        }

        return (new BinaryFileResponse($filePath, 200, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], false))->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $safeName,
            $fallbackName,
        );
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Lesson(['status' => 'draft']));
    }

    public function uploadAttachment(Request $request)
    {
        $this->teacher($request);
        $request->validate(['attachment' => ['required', 'file', 'mimes:'.config('lesson_uploads.mimes'), new LessonAttachmentSize]]);
        $file = $request->file('attachment');
        $this->validateVideoFile($file->getRealPath(), $file->getClientOriginalName());
        $token = (string) Str::uuid();
        $directory = "lesson-staging/{$request->user()->id}";
        $path = $file->storeAs($directory, $token.'.'.$file->extension(), 'local');
        abort_unless(is_string($path), 507, 'تعذر حفظ الملف. تواصل مع الإدارة.');

        return response()->json(['token' => $token, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'path' => $path], 201);
    }

    public function discardAttachment(Request $request, string $token)
    {
        $this->teacher($request);
        Storage::disk('local')->deleteDirectory("lesson-staging/{$request->user()->id}/{$token}");
        foreach (Storage::disk('local')->files("lesson-staging/{$request->user()->id}") as $path) {
            if (basename($path) === $token.'.'.pathinfo($path, PATHINFO_EXTENSION)) {
                Storage::disk('local')->delete($path);
            }
        }

        return response()->noContent();
    }

    public function store(TeacherLessonRequest $request): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $data = $request->validated();
        $classroomIds = $this->classroomIds($data, $teacher);
        $this->assertPairs($teacher, $classroomIds, (int) $data['subject_id']);

        $submissionKey = $data['submission_key'] ?? null;
        if ($submissionKey) {
            $existing = Lesson::where('submission_key', $submissionKey)->first();
            if ($existing) {
                abort_unless((int) $existing->teacher_id === (int) $teacher->id, 404);

                return redirect()->route('teacher.lessons.index')->with('success', 'تم حفظ الدرس بنجاح.');
            }
        }

        $this->prepareAttachments($request);
        try {
            DB::transaction(function () use ($data, $classroomIds, $teacher, $request, $submissionKey): void {
                $lessonData = $this->lessonData($data, $teacher->id, $classroomIds);
                if ($submissionKey) {
                    $lessonData['submission_key'] = $submissionKey;
                }
                $lesson = Lesson::create($lessonData);
                $lesson->classrooms()->sync($classroomIds);
                $this->storeAttachment($request, $lesson);
                AuditService::record('created', 'lessons', $lesson);
            });
        } catch (QueryException $exception) {
            $existing = $submissionKey ? Lesson::where('submission_key', $submissionKey)->first() : null;
            if (! $existing || (int) $existing->teacher_id !== (int) $teacher->id) {
                throw $exception;
            }
        }

        $lesson = Lesson::where('submission_key', $submissionKey)->when(! $submissionKey, fn ($query) => $query->latest('id'))->first();
        if ($lesson?->status === 'published' && $lesson->published_at?->lte(now())) {
            app(ParentNotificationService::class)->sendToClassrooms($classroomIds, [
                'title' => 'درس جديد',
                'body' => 'نُشر درس جديد: '.$lesson->title,
                'url' => route('parent.children.index'),
                'category' => 'lesson',
                'lesson_id' => $lesson->id,
            ]);
        }

        return redirect()->route('teacher.lessons.index')->with('success', 'تم حفظ الدرس بنجاح.');
    }

    public function edit(Request $request, Lesson $lesson): View
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $lesson->teacher_id === (int) $teacher->id, 404);

        return $this->form($request, $lesson->load(['attachments', 'classrooms']));
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
        $classroomIds = $this->classroomIds($data, $teacher);
        $this->assertPairs($teacher, $classroomIds, (int) $data['subject_id']);
        $this->prepareAttachments($request);
        DB::transaction(function () use ($lesson, $data, $classroomIds, $teacher, $request): void {
            $lesson->update($this->lessonData($data, $teacher->id, $classroomIds, $lesson));
            $lesson->classrooms()->sync($classroomIds);
            $this->storeAttachment($request, $lesson);
            AuditService::record('updated', 'lessons', $lesson);
        });

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

        $referer = $request->headers->get('referer');
        $refererHost = is_string($referer) ? parse_url($referer, PHP_URL_HOST) : null;
        $refererPath = is_string($referer) ? parse_url($referer, PHP_URL_PATH) : null;
        if ($refererHost === $request->getHost() && $refererPath === parse_url(route('teacher.lessons.index'), PHP_URL_PATH)) {
            $query = [];
            parse_str((string) parse_url($referer, PHP_URL_QUERY), $query);
            $filters = [];
            if (in_array($query['status'] ?? null, ['draft', 'scheduled', 'published', 'cancelled'], true)) {
                $filters['status'] = $query['status'];
            }
            if (filter_var($query['classroom_id'] ?? null, FILTER_VALIDATE_INT)
                && $this->assignedClassroomIds($teacher)->contains((int) $query['classroom_id'])) {
                $filters['classroom_id'] = (int) $query['classroom_id'];
            }
            if (is_string($query['published_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['published_at'])) {
                $filters['published_at'] = $query['published_at'];
            }

            $remaining = Lesson::where('teacher_id', $teacher->id)
                ->when(isset($filters['classroom_id']), fn ($builder) => $builder
                    ->where(fn ($query) => $query->where('classroom_id', $filters['classroom_id'])
                        ->orWhereHas('classrooms', fn ($classrooms) => $classrooms->whereKey($filters['classroom_id']))))
                ->when(isset($filters['published_at']), fn ($builder) => $builder->whereDate('published_at', $filters['published_at']))
                ->when(isset($filters['status']), function ($builder) use ($filters): void {
                    if ($filters['status'] === 'draft' || $filters['status'] === 'cancelled') {
                        $builder->where('status', $filters['status']);
                    } elseif ($filters['status'] === 'scheduled') {
                        $builder->where('status', 'published')->where('published_at', '>', now());
                    } else {
                        $builder->where('status', 'published')->where('published_at', '<=', now());
                    }
                })
                ->count();
            $lastPage = max(1, (int) ceil($remaining / 15));
            $requestedPage = max(1, (int) ($query['page'] ?? 1));
            $filters['page'] = min($requestedPage, $lastPage);

            return redirect()->route('teacher.lessons.index', $filters)->with('success', 'تم حذف الدرس.');
        }

        return back()->with('success', 'تم حذف الدرس.');
    }

    private function form(Request $request, Lesson $lesson): View
    {
        $teacher = $this->teacher($request);
        $pairs = $this->assignmentPairs($teacher);
        $classrooms = Classroom::with('academicYear')->whereIn('id', $pairs->pluck('classroom_id'))->orderBy('name')->orderBy('section')->get();
        $classrooms = TeacherLessonTargets::ordered($classrooms);
        $classrooms->each(function (Classroom $room): void {
            $room->setAttribute('lesson_grade_key', TeacherLessonTargets::gradeKey($room));
            $room->setAttribute('lesson_branch_label', TeacherLessonTargets::branchLabel($room));
        });
        $lessonGrades = TeacherLessonTargets::groups($classrooms, $pairs);
        $subjects = Subject::whereIn('id', $pairs->pluck('subject_id'))->get();
        $publicationTimezone = LessonPublicationTime::timezone();
        $schoolNow = now()->setTimezone($publicationTimezone);

        return view('teacher.lessons.form', compact('lesson', 'classrooms', 'subjects', 'pairs', 'lessonGrades', 'publicationTimezone', 'schoolNow'));
    }

    private function classroomIds(array $data, Teacher $teacher): array
    {
        $selected = array_values(array_unique(array_map(
            'intval',
            $data['classroom_ids'] ?? (isset($data['classroom_id']) ? [$data['classroom_id']] : [])
        )));
        if (empty($data['grade_key'])) {
            return $selected;
        }

        $eligibleIds = $this->assignmentPairs($teacher)->where('subject_id', (int) $data['subject_id'])->pluck('classroom_id');
        $eligibleIds = Classroom::whereIn('id', $eligibleIds)->get()
            ->filter(fn (Classroom $room) => TeacherLessonTargets::gradeKey($room) === $data['grade_key'])
            ->values()
            ->modelKeys();

        if (($data['branch_scope'] ?? 'selected') === 'all') {
            return $eligibleIds;
        }
        abort_if(array_diff($selected, $eligibleIds) !== [], 403, 'اختر الشعب المسندة إليك في الصف والمادة المحددين.');

        return $selected;
    }

    private function assertPairs($teacher, array $classroomIds, int $subjectId): void
    {
        abort_if($classroomIds === [], 422, 'اختر شعبة واحدة على الأقل.');
        foreach ($classroomIds as $classroomId) {
            abort_unless($this->ownsPair($teacher, $classroomId, $subjectId), 403);
        }
    }

    private function lessonData(array $data, int $teacherId, array $classroomIds, ?Lesson $lesson = null): array
    {
        $publishMode = $data['publish_mode'] ?? null;
        $publishedAt = $publishMode === 'preserve'
            ? $lesson?->published_at
            : (! empty($data['published_at'])
                ? ($publishMode === 'scheduled' ? LessonPublicationTime::parse($data['published_at']) : Carbon::parse($data['published_at']))
                : null);

        $unit = ! empty(trim($data['unit_title'] ?? ''))
            ? Unit::firstOrCreate(['subject_id' => $data['subject_id'], 'title' => trim($data['unit_title'])], ['position' => 0])
            : null;

        return [
            'teacher_id' => $teacherId,
            'classroom_id' => $classroomIds[0],
            'subject_id' => $data['subject_id'],
            'unit_id' => $unit?->id,
            'title' => $data['title'],
            'content' => $data['content'] ?? null,
            'status' => $data['status'],
            'published_at' => $data['status'] === 'published'
                ? ($publishMode === 'preserve' ? $publishedAt : ($publishMode === 'now' ? now() : ($publishedAt?->isFuture() ? $publishedAt : now())))
                : null,
        ];
    }

    private function storeAttachment(TeacherLessonRequest $request, Lesson $lesson): void
    {
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
        foreach ($request->input('attachment_tokens', []) as $index => $token) {
            $directory = "lesson-staging/{$request->user()->id}";
            $source = collect(Storage::disk('local')->files($directory))->first(fn ($path) => str_starts_with(basename($path), $token.'.'));
            abort_unless($source && Storage::disk('local')->exists($source), 422, 'أحد المرفقات المؤقتة غير متاح. أعد رفعه.');
            $name = $request->input("attachment_names.{$index}", basename($source));
            $extension = pathinfo($source, PATHINFO_EXTENSION);
            $target = "lesson-attachments/{$lesson->id}/".Str::uuid().'.'.$extension;
            Storage::disk('local')->move($source, $target);
            $lesson->attachments()->create(['title' => $name, 'disk' => 'local', 'file_path' => $target, 'original_name' => $name, 'mime_type' => Storage::disk('local')->mimeType($target), 'size' => Storage::disk('local')->size($target)]);
        }
        foreach ($request->uploadIds() as $id) {
            app(LessonUploadService::class)->attach($id, $request->user()->id, $lesson);
        }
    }

    private function prepareAttachments(TeacherLessonRequest $request): void
    {
        // Media processes run before the lesson transaction, so validation never holds lesson row locks.
        foreach ($request->file('attachments', []) ?? [] as $file) {
            $this->validateVideoFile($file->getRealPath(), $file->getClientOriginalName());
        }
        $disk = Storage::disk('local');
        foreach ($request->validated('attachment_tokens', []) ?? [] as $token) {
            $source = collect($disk->files('lesson-staging/'.$request->user()->id))->first(fn ($path) => str_starts_with(basename($path), $token.'.'));
            abort_unless($source && $disk->exists($source), 422, 'أحد المرفقات المؤقتة غير متاح. أعد رفعه.');
            $this->validateVideoFile($disk->path($source), basename($source));
        }
        foreach ($request->uploadIds() as $id) {
            app(LessonUploadService::class)->complete($id, $request->user()->id);
        }
    }

    private function validateVideoFile(string $path, string $name): void
    {
        $detectedMime = File::mimeType($path) ?: null;
        if (filesize($path) > LessonAttachmentSize::maximumForName($name, $detectedMime)) {
            throw ValidationException::withMessages(['attachments' => LessonAttachmentSize::messageForName($name, $detectedMime)]);
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'mp4' || $detectedMime === 'video/mp4') {
            app(LessonVideoValidator::class)->validate($path);
        }
    }
}
