<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Services\TeacherReportService;
use App\Services\TeacherReportSources;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    use InteractsWithTeacherScope;

    public function index(Request $request, TeacherReportService $reports)
    {
        $dashboard = $reports->dashboard($this->teacher($request));
        $ready = $reports->ready();

        return response()->view('teacher.reports.index', compact('dashboard', 'ready'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, TeacherReportService $reports)
    {
        $data = $request->validate(['classroom_id' => ['required', 'integer', 'min:1']]);
        $builder = $reports->build($this->teacher($request), (int) $data['classroom_id']);

        return response()->view('teacher.reports.editor', compact('builder'))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, TeacherReportService $reports)
    {
        $data = $this->validated($request);
        $builder = $reports->build($this->teacher($request), (int) $data['classroom_id'], $data['config'] ?? [], $data['entries'] ?? [], (string) ($data['title'] ?? ''));

        return response()->json(compact('builder'))->header('Cache-Control', 'private, no-store');
    }

    public function sources(Request $request, TeacherReportSources $sources)
    {
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'min:1'],
            'sources' => ['required', 'array', 'max:500'],
            'sources.*' => ['required', 'array:key,subject_id,assessment_type,assessment_date'],
            'sources.*.key' => ['required', 'string', 'max:160', 'distinct'],
            'sources.*.subject_id' => ['nullable', 'integer', 'min:1'],
            'sources.*.assessment_type' => ['nullable', Rule::in(['weekly', 'monthly', 'midterm', 'final', 'other'])],
            'sources.*.assessment_date' => ['nullable', 'date_format:Y-m-d', 'date'],
        ]);
        $catalog = $sources->saveMetadata($this->teacher($request), (int) $data['classroom_id'], $data['sources']);

        return response()->json(compact('catalog'))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, TeacherReportService $reports)
    {
        $data = $this->validated($request, true);
        $builder = $reports->save($this->teacher($request), $data);

        return response()->json(['builder' => $builder, 'redirect_url' => route('teacher.reports.show', $builder['report']['id'])], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $report, TeacherReportService $reports)
    {
        $builder = $reports->load($this->teacher($request), $report);
        if ($request->expectsJson()) {
            return response()->json(compact('builder'))->header('Cache-Control', 'private, no-store');
        }

        return response()->view('teacher.reports.editor', compact('builder'))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $report, TeacherReportService $reports)
    {
        $data = $this->validated($request, true, true);
        $builder = $reports->save($this->teacher($request), $data, $report);

        return response()->json(compact('builder'))->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $request, int $report, TeacherReportService $reports)
    {
        $filters = $request->validate(['format' => ['nullable', Rule::in(['cards', 'class'])], 'student_id' => ['nullable', 'integer', 'min:1']]);
        $builder = $reports->load($this->teacher($request), $report);
        $printFormat = $filters['format'] ?? 'cards';
        $printStudent = isset($filters['student_id']) ? (int) $filters['student_id'] : null;
        if ($printStudent !== null) {
            abort_unless(collect($builder['entries'])->contains(fn ($entry) => (int) $entry['student_id'] === $printStudent), 404);
            $builder['entries'] = array_values(array_filter($builder['entries'], fn ($entry) => (int) $entry['student_id'] === $printStudent));
        }

        return response()->view('teacher.reports.print', compact('builder', 'printFormat', 'printStudent'))->header('Cache-Control', 'private, no-store');
    }

    private function validated(Request $request, bool $saving = false, bool $updating = false): array
    {
        return $request->validate([
            'classroom_id' => ['required', 'integer', 'min:1'],
            'title' => [$saving ? 'required' : 'nullable', 'string', 'max:255'],
            'revision' => [$updating ? 'required' : 'nullable', 'integer', 'min:0'],
            'refresh_sources' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array:report_type,from,to,subject_ids,categories,source_keys,thresholds,aggregation'],
            'config.report_type' => ['sometimes', Rule::in(TeacherReportService::TYPES)],
            'config.from' => ['nullable', 'date_format:Y-m-d', 'date'],
            'config.to' => ['nullable', 'date_format:Y-m-d', 'date'],
            'config.subject_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'config.subject_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'config.categories' => ['nullable', 'array', 'max:5'],
            'config.categories.*' => ['required', Rule::in(['weekly', 'monthly', 'midterm', 'final', 'other']), 'distinct'],
            'config.source_keys' => ['nullable', 'array', 'max:500'],
            'config.source_keys.*' => ['required', 'string', 'max:160', 'distinct'],
            'config.thresholds' => ['sometimes', 'array:excellent,very_good,good'],
            'config.thresholds.excellent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'config.thresholds.very_good' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'config.thresholds.good' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'config.aggregation' => ['sometimes', Rule::in(['mean', 'points'])],
            'entries' => ['sometimes', 'array', 'max:10000'],
            'entries.*' => ['required', 'array:student_id,subject_id,score_mode,score,rating_mode,rating,behaviour,activity,notes'],
            'entries.*.student_id' => ['required', 'integer', 'min:1'],
            'entries.*.subject_id' => ['required', 'integer', 'min:1'],
            'entries.*.score_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'entries.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'entries.*.rating_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'entries.*.rating' => ['nullable', Rule::in(TeacherReportService::RATINGS)],
            'entries.*.behaviour' => ['nullable', Rule::in(['calm', 'disruptive'])],
            'entries.*.activity' => ['nullable', 'string', 'max:500'],
            'entries.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
