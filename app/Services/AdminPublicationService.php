<?php

namespace App\Services;

use App\Models\Assignment;
use App\Support\AssignmentDeadline;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A read-only index of academic content, independent of the teacher's current assignments. */
class AdminPublicationService
{
    private const SOURCES = ['lesson' => 'lessons', 'assignment' => 'assignments', 'grade' => 'grade_sheets', 'exam' => 'exams'];

    private const TYPES = ['lesson' => 'درس', 'assignment' => 'واجب', 'grade' => 'درجات', 'exam' => 'اختبار'];

    private const STATUSES = ['published' => 'منشور', 'scheduled' => 'مجدول', 'draft' => 'مسودة', 'archived' => 'مؤرشف / ملغى'];

    private array $columns = [];

    private array $tables = [];

    private Collection $classroomCatalog;

    public function index(array $filters = []): array
    {
        $this->prepare();
        $filters = array_replace(['q' => '', 'type' => 'all', 'status' => 'published', 'teacher_id' => null,
            'subject_id' => null, 'classroom_id' => null, 'from' => null, 'to' => null, 'view' => 'classes', 'page' => 1], $filters);
        $filters['type'] = $filters['type'] ?: 'all';
        $filters['status'] = $filters['status'] ?: 'published';
        $filters['view'] = $filters['view'] ?: 'classes';
        $filters['page'] = max(1, (int) ($filters['page'] ?: 1));
        $filters['q'] = Str::limit(trim((string) $filters['q']), 160, '');
        $query = $this->filtered($filters);
        $page = max(1, (int) $filters['page']);
        $activities = (clone $query)->orderByDesc('p.sort_at')->orderBy('p.type')->orderByDesc('p.id')
            ->paginate(20, ['p.*', 'u.name as teacher_name', 's.name as subject_name'], 'page', $page);
        $activities->setCollection($this->cards($activities->getCollection()));
        $activities->appends(array_filter($filters, fn ($value, $key) => $key !== 'page' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH));

        $totals = $this->totals(clone $query);
        $cardFilters = array_replace($filters, ['classroom_id' => null]);
        $classQuery = $this->filtered($cardFilters);
        $classCounts = DB::query()->fromSub($classQuery->select('p.*'), 'p')
            ->joinSub($this->classMap(), 'pc', fn ($join) => $join->on('pc.publication_id', '=', 'p.id')->on('pc.type', '=', 'p.type'))
            ->selectRaw('pc.classroom_id, p.type, COUNT(*) AS total, MAX(p.updated_at) AS latest_at')
            ->groupBy('pc.classroom_id', 'p.type')->get()->groupBy('classroom_id');
        $classrooms = $this->classroomCatalog->map(function ($classroom) use ($classCounts, $filters) {
            $counts = $this->emptyCounts();
            $latest = null;
            foreach ($classCounts->get($classroom->id, collect()) as $count) {
                $counts[$count->type] = (int) $count->total;
                $counts['total'] += (int) $count->total;
                $latest = max($latest ?? '', $count->latest_at ?? '') ?: null;
            }
            $row = $this->classroom($classroom) + ['name' => $classroom->name, 'section' => $classroom->section ?? null,
                'counts' => $counts, 'latest_at' => $this->iso($latest), 'latest_teacher' => null];
            $academicFilter = $filters['type'] !== 'all' || ! empty($filters['teacher_id']) || ! empty($filters['subject_id']) || ! empty($filters['from']) || ! empty($filters['to']);
            if ($counts['total'] === 0 && (($filters['q'] !== '' && ! $this->matches($row['label'], $filters['q'])) || $academicFilter)) {
                return null;
            }

            return $row;
        })->filter()->values()->all();

        $options = $this->options();
        $fingerprint = hash('sha256', json_encode([$filters, $classrooms, $totals, $activities->total(), $activities->items(), $options], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        $selected_classroom = $this->classroomCatalog->firstWhere('id', (int) ($filters['classroom_id'] ?? 0));
        $selected_classroom = $selected_classroom ? $this->classroom($selected_classroom) : null;

        return compact('filters', 'classrooms', 'options', 'activities', 'totals', 'fingerprint', 'selected_classroom');
    }

    public function detail(string $type, int $id): array
    {
        $this->prepare();
        abort_unless(isset(self::SOURCES[$type]) && $this->hasTable(self::SOURCES[$type]), 404);
        $metadata = $this->metadataQuery($type)->where('p.id', $id)->first(['p.*', 'u.name as teacher_name', 's.name as subject_name']);
        abort_unless($metadata, 404);
        $row = DB::table(self::SOURCES[$type])->where('id', $id)->first();
        abort_unless($row, 404);
        $card = $this->cards(collect([$metadata]))->first();
        // A deleted class is not offered for new navigation, but its actual historical label remains in the record.
        $classroomIds = array_filter([(int) ($row->classroom_id ?? 0)]);
        if (in_array($type, ['lesson', 'assignment'], true) && $this->hasTable($type.'_classroom')) {
            $classroomIds = array_merge($classroomIds, DB::table($type.'_classroom')->where($type.'_id', $id)->pluck('classroom_id')->map(fn ($value) => (int) $value)->all());
        }
        if ($classroomIds && $this->hasTable('classrooms')) {
            $card['classrooms'] = DB::table('classrooms')->whereIn('id', array_unique($classroomIds))->orderBy('id')->get()->map(fn ($room) => $this->classroom($room))->all();
        }
        $detail = $card + ['content' => $this->body($type, $row), 'metadata' => [], 'attachments' => [],
            'grade_rows' => [], 'grade_columns' => [], 'questions' => []];
        $detail['metadata'] = [
            ['label' => 'المعلم', 'value' => $card['teacher']['name']],
            ['label' => 'الصفوف والشعب', 'value' => collect($card['classrooms'])->pluck('label')->implode('، ') ?: 'غير محدد في السجل'],
            ['label' => 'الحالة', 'value' => $card['status_label']],
        ];
        if ($card['subject']) {
            $detail['metadata'][] = ['label' => 'المادة', 'value' => $card['subject']['name']];
        }
        if ($type === 'lesson' || $type === 'assignment') {
            $detail['attachments'] = $this->attachments($type, $id, $row);
        }
        if ($type === 'assignment') {
            $deadline = AssignmentDeadline::for(new Assignment(['due_at' => $row->due_at ?? null, 'due_date' => $row->due_date ?? null]));
            $detail['metadata'][] = ['label' => 'آخر موعد للتسليم', 'value' => $this->displayDate($deadline?->toIso8601String())];
            if ($this->hasTable('assignment_submissions')) {
                $detail['metadata'][] = ['label' => 'التسليمات المستلمة', 'value' => (string) DB::table('assignment_submissions')->where('assignment_id', $id)->whereNotNull('submitted_at')->count()];
            }
        }
        if ($type === 'grade') {
            [$detail['grade_columns'], $detail['grade_rows']] = $this->gradeDetails($row);
        }
        if ($type === 'exam') {
            $detail['metadata'][] = ['label' => 'موعد الاختبار', 'value' => $this->displayDate($row->starts_at ?? null)];
            $detail['metadata'][] = ['label' => 'المدة بالدقائق', 'value' => (string) ($row->duration_minutes ?? '—')];
            $detail['metadata'][] = ['label' => 'الدرجة القصوى', 'value' => (string) ($row->total_score ?? '—')];
            $detail['questions'] = $this->examQuestions($id);
        }

        return $detail;
    }

    private function prepare(): void
    {
        // No cross-request cache: newly deployed tables and newly published records are visible on the next request.
        $this->columns = $this->tables = [];
        $this->classroomCatalog = $this->hasTable('classrooms')
            ? DB::table('classrooms')->when($this->hasColumn('classrooms', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->get()->sortBy(fn ($room) => $this->classroomSort($room))->values()
            : collect();
    }

    private function filtered(array $filters): Builder
    {
        $query = $this->metadataQuery();
        foreach (['type', 'status'] as $key) {
            if (! empty($filters[$key]) && $filters[$key] !== 'all') {
                $query->where('p.'.$key, $filters[$key]);
            }
        }
        if (! empty($filters['teacher_id'])) {
            $query->where('p.teacher_id', (int) $filters['teacher_id']);
        }
        if (! empty($filters['subject_id'])) {
            $query->where('p.subject_id', (int) $filters['subject_id']);
        }
        if (! empty($filters['classroom_id'])) {
            $query->whereExists(fn ($q) => $q->fromSub($this->classMap(), 'filter_classes')->selectRaw('1')
                ->whereColumn('filter_classes.publication_id', 'p.id')->whereColumn('filter_classes.type', 'p.type')
                ->where('filter_classes.classroom_id', (int) $filters['classroom_id']));
        }
        if (! empty($filters['from'])) {
            $query->where('p.sort_at', '>=', $this->dateBoundary($filters['from'], false));
        }
        if (! empty($filters['to'])) {
            $query->where('p.sort_at', '<=', $this->dateBoundary($filters['to'], true));
        }
        foreach (preg_split('/\s+/u', trim((string) ($filters['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->normalize($token)).'%';
            $query->where(function ($q) use ($pattern) {
                foreach (['p.title', 'u.name', 's.name'] as $column) {
                    $q->orWhereRaw($this->normalizedSql($column)." LIKE ? ESCAPE '!'", [$pattern]);
                }
                $q->orWhereExists(function ($classes) use ($pattern) {
                    $classes->fromSub($this->classMap(), 'search_classes')->join('classrooms as search_room', 'search_room.id', '=', 'search_classes.classroom_id')->selectRaw('1')
                        ->whereColumn('search_classes.publication_id', 'p.id')->whereColumn('search_classes.type', 'p.type')
                        ->where(fn ($labels) => $labels->whereRaw($this->normalizedSql('search_room.name')." LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw($this->normalizedSql('search_room.section')." LIKE ? ESCAPE '!'", [$pattern]));
                });
                // Search content in SQL; bodies are hydrated only for the requested page.
                foreach (['lesson' => ['content'], 'assignment' => ['instructions', 'description']] as $type => $bodyColumns) {
                    $table = self::SOURCES[$type];
                    $existing = array_filter($bodyColumns, fn ($column) => $this->hasColumn($table, $column));
                    if (! $this->hasTable($table) || ! $existing) {
                        continue;
                    }
                    $q->orWhere(fn ($source) => $source->where('p.type', $type)->whereExists(function ($body) use ($table, $existing, $pattern) {
                        $body->from($table.' as search_body')->selectRaw('1')->whereColumn('search_body.id', 'p.id')
                            ->where(function ($text) use ($existing, $pattern) {
                                foreach ($existing as $column) {
                                    $text->orWhereRaw($this->normalizedSql('search_body.'.$column)." LIKE ? ESCAPE '!'", [$pattern]);
                                }
                            });
                    }));
                }
            });
        }

        return $query;
    }

    private function metadataQuery(?string $onlyType = null): Builder
    {
        $sources = [];
        foreach (self::SOURCES as $type => $table) {
            if (($onlyType && $onlyType !== $type) || ! $this->hasTable($table) || ! $this->hasColumn($table, 'teacher_id')) {
                continue;
            }
            $sources[] = $this->sourceMetadata($type, $table);
        }
        if (! $sources) {
            // Typed empty projection works on PostgreSQL as well as SQLite, including pagination/counts.
            $union = DB::query()->selectRaw('CAST(NULL AS BIGINT) AS id, CAST(NULL AS VARCHAR(20)) AS type, CAST(NULL AS VARCHAR(255)) AS title, CAST(NULL AS BIGINT) AS teacher_id, CAST(NULL AS BIGINT) AS subject_id, CAST(NULL AS BIGINT) AS classroom_id, CAST(NULL AS VARCHAR(20)) AS status, CAST(NULL AS TIMESTAMP) AS published_at, CAST(NULL AS TIMESTAMP) AS updated_at, CAST(NULL AS TIMESTAMP) AS created_at, CAST(NULL AS TIMESTAMP) AS sort_at')->whereRaw('1 = 0');
        } else {
            $union = array_shift($sources);
            foreach ($sources as $source) {
                $union->unionAll($source);
            }
        }
        $query = DB::query()->fromSub($union, 'p');
        if ($this->hasTable('teachers') && $this->hasTable('users')) {
            $query->leftJoin('teachers as t', 't.id', '=', 'p.teacher_id')->leftJoin('users as u', 'u.id', '=', 't.user_id');
        } else {
            $query->leftJoinSub(DB::query()->selectRaw('CAST(NULL AS BIGINT) AS id, CAST(NULL AS VARCHAR(255)) AS name')->whereRaw('1 = 0'), 'u', 'u.id', '=', 'p.teacher_id');
        }
        if ($this->hasTable('subjects')) {
            $query->leftJoin('subjects as s', 's.id', '=', 'p.subject_id');
        } else {
            $query->leftJoinSub(DB::query()->selectRaw('CAST(NULL AS BIGINT) AS id, CAST(NULL AS VARCHAR(255)) AS name')->whereRaw('1 = 0'), 's', 's.id', '=', 'p.subject_id');
        }

        return $query;
    }

    private function sourceMetadata(string $type, string $table): Builder
    {
        $created = $this->columnOrNull($table, 'created_at');
        $updated = 'COALESCE('.$this->columnOrNull($table, 'updated_at').', '.$created.')';
        $published = $this->columnOrNull($table, 'published_at');
        $rawStatus = $this->hasColumn($table, 'status') ? 'src.status' : "'draft'";
        $now = now()->format('Y-m-d H:i:s');
        $statusBindings = [];
        if ($type === 'lesson') {
            $status = "CASE WHEN {$rawStatus} = 'published' AND {$published} IS NOT NULL AND {$published} <= ? THEN 'published' WHEN {$rawStatus} = 'published' AND {$published} > ? THEN 'scheduled' WHEN {$rawStatus} IN ('draft', 'published') THEN 'draft' ELSE 'archived' END";
            $statusBindings = [$now, $now];
            $publication = $published;
        } elseif ($type === 'assignment') {
            $status = "CASE WHEN {$rawStatus} IN ('active', 'published', 'closed', 'completed') AND ({$published} IS NULL OR {$published} <= ?) THEN 'published' WHEN {$rawStatus} IN ('active', 'published', 'closed', 'completed', 'scheduled') AND {$published} > ? THEN 'scheduled' WHEN {$rawStatus} = 'draft' THEN 'draft' ELSE 'archived' END";
            $statusBindings = [$now, $now];
            $publication = 'COALESCE('.$published.', '.$created.', '.$updated.')';
        } elseif ($type === 'exam') {
            $status = "CASE WHEN {$rawStatus} IN ('published', 'completed') THEN 'published' WHEN {$rawStatus} = 'scheduled' THEN 'scheduled' WHEN {$rawStatus} = 'draft' THEN 'draft' ELSE 'archived' END";
            $publication = 'COALESCE('.$updated.', '.$created.')';
        } else {
            $status = 'CASE WHEN '.$this->meaningfulGradeSql($table).' THEN \'published\' ELSE \'draft\' END';
            $publication = 'COALESCE('.$updated.', '.$created.')';
        }
        $title = $type === 'grade' ? "'كشف درجات'" : $this->columnOrNull($table, 'title', 'VARCHAR(255)');

        return DB::table($table.' as src')->whereNotNull('src.teacher_id')
            ->selectRaw('src.id, ? AS type, '.$title.' AS title, src.teacher_id, '.$this->columnOrNull($table, 'subject_id', 'BIGINT').' AS subject_id, '.$this->columnOrNull($table, 'classroom_id', 'BIGINT').' AS classroom_id, '.$status.' AS status, '.$publication.' AS published_at, '.$updated.' AS updated_at, '.$created.' AS created_at, COALESCE('.$publication.', '.$updated.', '.$created.') AS sort_at', array_merge([$type], $statusBindings));
    }

    /** One row for each actual publication/class pair; UNION removes primary/pivot duplicates. */
    private function classMap(): Builder
    {
        $parts = [];
        foreach (self::SOURCES as $type => $table) {
            if (! $this->hasTable($table)) {
                continue;
            }
            if ($this->hasColumn($table, 'classroom_id')) {
                $parts[] = DB::table($table.' as map_src')->whereNotNull('map_src.classroom_id')->selectRaw('? AS type, map_src.id AS publication_id, map_src.classroom_id', [$type]);
            }
            if (in_array($type, ['lesson', 'assignment'], true) && $this->hasTable($type.'_classroom')) {
                $parts[] = DB::table($type.'_classroom as map_pivot')->selectRaw('? AS type, map_pivot.'.$type.'_id AS publication_id, map_pivot.classroom_id', [$type]);
            }
        }
        $union = array_shift($parts) ?? DB::query()->selectRaw('CAST(NULL AS VARCHAR(20)) AS type, CAST(NULL AS BIGINT) AS publication_id, CAST(NULL AS BIGINT) AS classroom_id')->whereRaw('1 = 0');
        foreach ($parts as $part) {
            $union->union($part);
        }

        return DB::query()->fromSub($union, 'class_map')->whereIn('classroom_id', $this->classroomCatalog->pluck('id'))->select('type', 'publication_id', 'classroom_id')->distinct();
    }

    private function cards(Collection $records): Collection
    {
        $sourceRows = [];
        foreach ($records->groupBy('type') as $type => $group) {
            $sourceRows[$type] = DB::table(self::SOURCES[$type])->whereIn('id', $group->pluck('id'))->get()->keyBy('id');
        }
        $classMap = $records->isEmpty() ? collect() : $this->classMap()->where(function ($q) use ($records) {
            foreach ($records->groupBy('type') as $type => $group) {
                $q->orWhere(fn ($part) => $part->where('type', $type)->whereIn('publication_id', $group->pluck('id')));
            }
        })->get()->groupBy(fn ($row) => $row->type.':'.$row->publication_id);
        $classes = $this->classroomCatalog->keyBy('id');
        $contexts = $this->gradeSubjectContexts($records);

        return $records->map(function ($record) use ($sourceRows, $classMap, $classes, $contexts) {
            $type = $record->type;
            $row = $sourceRows[$type]->get($record->id);
            $key = $type.':'.$record->id;
            $body = $row ? $this->body($type, $row) : '';

            return ['type' => $type, 'type_label' => self::TYPES[$type], 'id' => (int) $record->id, 'key' => $key,
                'title' => (string) ($record->title ?: self::TYPES[$type]), 'status' => $record->status,
                'status_label' => self::STATUSES[$record->status] ?? 'غير محدد',
                'published_at' => $this->iso($record->published_at), 'updated_at' => $this->iso($record->updated_at), 'created_at' => $this->iso($record->created_at),
                'published_label' => match ($type) {
                    'grade' => 'آخر تحديث للكشف',
                    'exam' => 'آخر تحديث للاختبار',
                    'assignment' => empty($row->published_at) ? 'تاريخ الحفظ' : ($record->status === 'scheduled' ? 'موعد النشر' : 'تاريخ النشر'),
                    default => $record->status === 'draft' ? 'تاريخ الإنشاء' : ($record->status === 'scheduled' ? 'موعد النشر' : 'تاريخ النشر'),
                },
                'teacher' => ['id' => (int) $record->teacher_id, 'name' => (string) ($record->teacher_name ?: 'معلم غير متاح')],
                'subject' => $record->subject_id ? ['id' => (int) $record->subject_id, 'name' => (string) ($record->subject_name ?: 'مادة غير متاحة')] : null,
                'subject_context' => $contexts[$key] ?? [],
                'classrooms' => $classMap->get($key, collect())->sortBy('classroom_id')->map(fn ($map) => $classes->has($map->classroom_id) ? $this->classroom($classes->get($map->classroom_id)) : null)->filter()->values()->all(),
                'summary' => $type === 'grade' ? 'كشف الدرجات المحفوظ للصف، مع أسماء الطلاب والدرجات المسجلة.' : Str::limit($this->plainText($body), 240),
            ];
        });
    }

    private function totals(Builder $query): array
    {
        $totals = $this->emptyCounts();
        foreach ($query->select('p.type')->selectRaw('COUNT(*) AS total')->groupBy('p.type')->get() as $row) {
            $totals[$row->type] = (int) $row->total;
            $totals['total'] += (int) $row->total;
        }

        return $totals;
    }

    private function options(): array
    {
        $teachers = $this->hasTable('teachers') && $this->hasTable('users')
            ? DB::table('teachers')->join('users', 'users.id', '=', 'teachers.user_id')->orderBy('users.name')->get(['teachers.id', 'users.name'])->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])->all() : [];
        $subjects = $this->hasTable('subjects') ? DB::table('subjects')->orderBy('name')->get(['id', 'name'])->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])->all() : [];

        return ['teachers' => $teachers, 'subjects' => $subjects, 'classrooms' => $this->classroomCatalog->map(fn ($row) => $this->classroom($row))->values()->all(),
            'types' => collect(self::TYPES)->map(fn ($label, $value) => compact('value', 'label'))->values()->all(),
            'statuses' => collect(['all' => 'كل الحالات'] + self::STATUSES)->map(fn ($label, $value) => compact('value', 'label'))->values()->all()];
    }

    private function gradeSubjectContexts(Collection $records): array
    {
        $grades = $records->where('type', 'grade');
        if ($grades->isEmpty() || ! $this->hasTable('teacher_assignments') || ! $this->hasTable('subjects')) {
            return [];
        }
        $assignments = DB::table('teacher_assignments as a')->join('subjects as s', 's.id', '=', 'a.subject_id')
            ->whereIn('a.teacher_id', $grades->pluck('teacher_id'))->whereIn('a.classroom_id', $grades->pluck('classroom_id'))
            ->select('a.teacher_id', 'a.classroom_id', 's.id', 's.name')->distinct()->orderBy('s.name')->get()->groupBy(fn ($row) => $row->teacher_id.':'.$row->classroom_id);

        return $grades->mapWithKeys(fn ($grade) => ['grade:'.$grade->id => $assignments->get($grade->teacher_id.':'.$grade->classroom_id, collect())->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])->all()])->all();
    }

    private function gradeDetails(object $row): array
    {
        $columns = $this->decoded($row->sheet_data ?? null);
        $columns = array_is_list($columns) ? $columns : [];
        $normalized = [];
        foreach ($columns as $column) {
            if (! is_array($column) || ! is_string($column['key'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $column['key'])) {
                continue;
            }
            if (isset($normalized[$column['key']])) {
                continue;
            }
            $maximum = array_key_exists('max_score', $column) ? $column['max_score'] : 100;
            if (! $this->validNumber($maximum, 100) || (float) $maximum <= 0) {
                continue;
            }
            $normalized[$column['key']] = ['key' => $column['key'], 'label' => is_string($column['title'] ?? null) ? $column['title'] : 'درجة مسجلة', 'max_score' => (float) $maximum];
        }
        if (! $columns) {
            foreach (['monthly' => 'اختبار شهري', 'midterm' => 'اختبار نصفي', 'work' => 'أعمال', 'activity' => 'نشاط'] as $key => $label) {
                $normalized[$key] = compact('key', 'label') + ['max_score' => 100.0];
            }
        }
        $scores = $this->decoded($row->column_scores ?? null);
        $overall = $this->decoded($row->scores ?? null);
        $studentIds = [];
        foreach ($normalized as $column) {
            foreach (is_array($scores[$column['key']] ?? null) ? $scores[$column['key']] : [] as $studentId => $score) {
                if ($this->validNumber($score, $column['max_score'])) {
                    $studentIds[] = (int) $studentId;
                }
            }
        }
        foreach ($overall as $studentId => $score) {
            if ($this->validNumber($score, 100)) {
                $studentIds[] = (int) $studentId;
            }
        }
        if (! $studentIds || ! $this->hasTable('students') || ! $this->hasTable('users')) {
            return [array_values($normalized), []];
        }
        $students = DB::table('students')->leftJoin('users', 'users.id', '=', 'students.user_id')->where('students.classroom_id', $row->classroom_id)->whereIn('students.id', array_unique($studentIds))->orderBy('users.name')->get(['students.id', 'users.name']);
        $rows = [];
        $hasOverall = false;
        foreach ($students as $student) {
            $marks = [];
            foreach ($normalized as $column) {
                $score = $scores[$column['key']][(string) $student->id] ?? null;
                if ($this->validNumber($score, $column['max_score'])) {
                    $marks[$column['key']] = $column + ['score' => (float) $score];
                }
            }
            $overallScore = $overall[(string) $student->id] ?? null;
            if ($this->validNumber($overallScore, 100)) {
                $marks['__overall'] = ['key' => '__overall', 'label' => 'المعدل المسجل', 'score' => (float) $overallScore, 'max_score' => 100.0];
                $hasOverall = true;
            }
            if ($marks) {
                $rows[] = ['student_id' => (int) $student->id, 'name' => (string) ($student->name ?: 'طالب غير متاح'), 'scores' => $marks];
            }
        }
        if ($hasOverall) {
            $normalized['__overall'] = ['key' => '__overall', 'label' => 'المعدل المسجل', 'max_score' => 100.0];
        }

        return [array_values($normalized), $rows];
    }

    /** Numeric JSON checks stay in the database, so polling never fetches every grade sheet. */
    private function meaningfulGradeSql(string $table): string
    {
        if (! $this->hasTable('students')) {
            return '1 = 0';
        }
        $driver = DB::connection()->getDriverName();
        $scores = $this->hasColumn($table, 'scores') ? 'src.scores' : 'NULL';
        $columnScores = $this->hasColumn($table, 'column_scores') ? 'src.column_scores' : 'NULL';
        $sheet = $this->hasColumn($table, 'sheet_data') ? 'src.sheet_data' : 'NULL';
        if ($driver === 'pgsql') {
            $object = fn ($column) => "CASE WHEN jsonb_typeof(CAST({$column} AS jsonb)) = 'object' THEN CAST({$column} AS jsonb) ELSE '{}'::jsonb END";
            $array = "CASE WHEN jsonb_typeof(CAST({$sheet} AS jsonb)) = 'array' THEN CAST({$sheet} AS jsonb) ELSE '[]'::jsonb END";
            $number = fn ($value) => "CASE WHEN jsonb_typeof({$value}) = 'number' THEN ({$value} #>> '{}')::numeric ELSE NULL END";
            $legacy = 'EXISTS (SELECT 1 FROM jsonb_each('.$object($scores).') mark JOIN students grade_student ON CAST(grade_student.id AS TEXT) = mark.key AND grade_student.classroom_id = src.classroom_id WHERE '.$number('mark.value').' BETWEEN 0 AND 100)';
            $definitionMaximum = "CASE WHEN definition.value -> 'max_score' IS NULL THEN 100 ELSE ".$number("definition.value -> 'max_score'").' END';
            $maximum = "CASE WHEN (jsonb_array_length({$array}) = 0 AND grade_column.key IN ('monthly', 'midterm', 'work', 'activity')) THEN 100 ELSE (SELECT {$definitionMaximum} FROM jsonb_array_elements({$array}) definition WHERE jsonb_typeof(definition.value -> 'key') = 'string' AND definition.value ->> 'key' = grade_column.key AND ({$definitionMaximum}) > 0 AND ({$definitionMaximum}) <= 100 LIMIT 1) END";
            $nested = 'EXISTS (SELECT 1 FROM jsonb_each('.$object($columnScores).') grade_column CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(grade_column.value) = \'object\' THEN grade_column.value ELSE \'{}\'::jsonb END) mark JOIN students grade_student ON CAST(grade_student.id AS TEXT) = mark.key AND grade_student.classroom_id = src.classroom_id WHERE grade_column.key ~ \'^[a-zA-Z0-9_-]{1,100}$\' AND '.$number('mark.value').' BETWEEN 0 AND ('.$maximum.'))';

            return '('.$legacy.' OR '.$nested.')';
        }
        if ($driver === 'sqlite') {
            $object = fn ($column) => "CASE WHEN json_valid({$column}) THEN CASE WHEN json_type({$column}) = 'object' THEN {$column} ELSE '{}' END ELSE '{}' END";
            $array = "CASE WHEN json_valid({$sheet}) THEN CASE WHEN json_type({$sheet}) = 'array' THEN {$sheet} ELSE '[]' END ELSE '[]' END";
            $legacy = 'EXISTS (SELECT 1 FROM json_each('.$object($scores).") mark JOIN students grade_student ON CAST(grade_student.id AS TEXT) = CAST(mark.key AS TEXT) AND grade_student.classroom_id = src.classroom_id WHERE mark.type IN ('integer', 'real') AND mark.value BETWEEN 0 AND 100)";
            $definition = "CASE WHEN definition.type = 'object' THEN definition.value ELSE '{}' END";
            $definitionMaximum = "CASE WHEN json_type({$definition}, '$.max_score') IS NULL THEN 100 WHEN json_type({$definition}, '$.max_score') IN ('integer','real') THEN json_extract({$definition}, '$.max_score') ELSE NULL END";
            $maximum = "CASE WHEN (json_array_length({$array}) = 0 AND grade_column.key IN ('monthly', 'midterm', 'work', 'activity')) THEN 100 ELSE (SELECT {$definitionMaximum} FROM json_each({$array}) definition WHERE json_type({$definition}, '$.key') = 'text' AND json_extract({$definition}, '$.key') = grade_column.key AND ({$definitionMaximum}) > 0 AND ({$definitionMaximum}) <= 100 LIMIT 1) END";
            $nested = 'EXISTS (SELECT 1 FROM json_each('.$object($columnScores).") grade_column JOIN json_each(CASE WHEN grade_column.type = 'object' THEN grade_column.value ELSE '{}' END) mark JOIN students grade_student ON CAST(grade_student.id AS TEXT) = CAST(mark.key AS TEXT) AND grade_student.classroom_id = src.classroom_id WHERE LENGTH(grade_column.key) BETWEEN 1 AND 100 AND grade_column.key NOT GLOB '*[^a-zA-Z0-9_-]*' AND mark.type IN ('integer', 'real') AND mark.value BETWEEN 0 AND ({$maximum}))";

            return '('.$legacy.' OR '.$nested.')';
        }
        // MySQL 8 / MariaDB JSON_TABLE accepts only numeric JSON values after the explicit type guard.
        $object = fn ($column) => "CASE WHEN JSON_VALID({$column}) THEN CASE WHEN JSON_TYPE({$column}) = 'OBJECT' THEN {$column} ELSE JSON_OBJECT() END ELSE JSON_OBJECT() END";
        $array = "CASE WHEN JSON_VALID({$sheet}) THEN CASE WHEN JSON_TYPE({$sheet}) = 'ARRAY' THEN {$sheet} ELSE JSON_ARRAY() END ELSE JSON_ARRAY() END";
        $legacyValue = 'JSON_EXTRACT('.$object($scores).", CONCAT('$.', JSON_QUOTE(mark.student_key)))";
        $legacy = 'EXISTS (SELECT 1 FROM JSON_TABLE(JSON_KEYS('.$object($scores)."), '$[*]' COLUMNS (student_key VARCHAR(40) PATH '$')) mark JOIN students grade_student ON CAST(grade_student.id AS CHAR) = mark.student_key AND grade_student.classroom_id = src.classroom_id WHERE JSON_TYPE({$legacyValue}) IN ('INTEGER','DOUBLE') AND CAST(JSON_UNQUOTE({$legacyValue}) AS DECIMAL(30,10)) BETWEEN 0 AND 100)";
        $maximum = "CASE WHEN JSON_LENGTH({$array}) = 0 AND grade_column.column_key IN ('monthly','midterm','work','activity') THEN 100 ELSE (SELECT definition.max_score FROM JSON_TABLE({$array}, '$[*]' COLUMNS (column_key VARCHAR(100) PATH '$.key', max_score DECIMAL(20,5) PATH '$.max_score' NULL ON ERROR)) definition WHERE definition.column_key = grade_column.column_key AND definition.max_score > 0 AND definition.max_score <= 100 LIMIT 1) END";
        $nestedValue = 'JSON_EXTRACT('.$object($columnScores).", CONCAT('$.', JSON_QUOTE(grade_column.column_key), '.', JSON_QUOTE(mark.student_key)))";
        $nestedObject = 'JSON_EXTRACT('.$object($columnScores).", CONCAT('$.', JSON_QUOTE(grade_column.column_key)))";
        $nested = 'EXISTS (SELECT 1 FROM JSON_TABLE(JSON_KEYS('.$object($columnScores)."), '$[*]' COLUMNS (column_key VARCHAR(100) PATH '$')) grade_column JOIN JSON_TABLE(JSON_KEYS(CASE WHEN JSON_TYPE({$nestedObject}) = 'OBJECT' THEN {$nestedObject} ELSE JSON_OBJECT() END), '$[*]' COLUMNS (student_key VARCHAR(40) PATH '$')) mark JOIN students grade_student ON CAST(grade_student.id AS CHAR) = mark.student_key AND grade_student.classroom_id = src.classroom_id WHERE JSON_TYPE({$nestedValue}) IN ('INTEGER','DOUBLE') AND CAST(JSON_UNQUOTE({$nestedValue}) AS DECIMAL(30,10)) BETWEEN 0 AND ({$maximum}))";

        return '('.$legacy.' OR '.$nested.')';
    }

    private function attachments(string $type, int $id, object $source): array
    {
        $table = $type === 'lesson' ? 'lesson_attachments' : 'assignment_attachments';
        $attachments = [];
        if ($this->hasTable($table)) {
            foreach (DB::table($table)->where($type.'_id', $id)->orderBy('id')->get() as $file) {
                $path = $file->path ?? $file->file_path ?? null;
                if (! is_string($path) || trim($path) === '') {
                    continue;
                }
                $attachments[] = ['id' => (int) $file->id, 'source' => $type, 'name' => $file->original_name ?? $file->title ?? basename($path),
                    'disk' => is_string($file->disk ?? null) && trim($file->disk) !== '' ? $file->disk : 'local', 'path' => $path,
                    'mime_type' => $file->mime_type ?? null, 'size' => $file->size ?? $file->file_size ?? null];
            }
        }
        $legacy = $source->attachment_path ?? null;
        if ($type === 'assignment' && is_string($legacy) && trim($legacy) !== '' && ! collect($attachments)->contains('path', $legacy)) {
            $attachments[] = ['id' => 'legacy', 'source' => 'assignment', 'name' => basename($legacy), 'disk' => 'local', 'path' => $legacy, 'mime_type' => null, 'size' => null];
        }

        return $attachments;
    }

    private function examQuestions(int $examId): array
    {
        if (! $this->hasTable('exam_questions')) {
            return [];
        }
        $questions = DB::table('exam_questions')->where('exam_id', $examId)->orderBy($this->hasColumn('exam_questions', 'position') ? 'position' : 'id')->get();
        $choices = $this->hasTable('exam_choices') ? DB::table('exam_choices')->whereIn('exam_question_id', $questions->pluck('id'))->orderBy('id')->get()->groupBy('exam_question_id') : collect();

        return $questions->map(fn ($question) => ['text' => (string) ($question->question_text ?? $question->text ?? ''), 'type' => $question->type,
            'options' => $choices->has($question->id) ? $choices->get($question->id)->pluck('text')->all() : $this->decoded($question->options ?? null),
            'score' => is_numeric($question->score ?? null) ? (float) $question->score : null, 'position' => (int) ($question->position ?? $question->order ?? 0)])->all();
    }

    private function body(string $type, object $row): string
    {
        return (string) match ($type) {
            'lesson' => $row->content ?? '',
            'assignment' => ($row->instructions ?? null) ?: ($row->description ?? ''),
            default => '',
        };
    }

    private function classroom(object $classroom): array
    {
        $name = trim((string) $classroom->name);
        $section = trim((string) ($classroom->section ?? ''));
        $hasSection = $section === '' || preg_match('/(?:^|[\s\-–—(])'.preg_quote($section, '/').'\)?$/ui', $name);

        return ['id' => (int) $classroom->id, 'label' => $name.($hasSection ? '' : ' '.$section)];
    }

    private function classroomSort(object $classroom): array
    {
        $name = $this->normalize((string) $classroom->name);
        $rank = 99;
        if (preg_match('/(?:التمهيدي|تمهيدي|reception|\brec\b)/ui', $name)) {
            $rank = 0;
        } else {
            foreach (['الاول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع'] as $index => $ordinal) {
                if (str_contains($name, $ordinal)) {
                    $rank = $index + 1 + (str_contains($name, 'ثانوي') ? 9 : 0);
                    break;
                }
            }
        }
        $section = $this->normalize((string) ($classroom->section ?? ''));
        $sectionRank = array_search($section, ['', 'a', 'ا', 'b', 'ب', 'c', 'ج'], true);

        return [$rank, $sectionRank === false ? 99 : $sectionRank, $name, (int) $classroom->id];
    }

    private function emptyCounts(): array
    {
        return ['lesson' => 0, 'assignment' => 0, 'grade' => 0, 'exam' => 0, 'total' => 0];
    }

    private function columnOrNull(string $table, string $column, string $type = 'TIMESTAMP'): string
    {
        return $this->hasColumn($table, $column) ? 'src.'.$column : 'CAST(NULL AS '.$type.')';
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (! $this->hasTable($table)) {
            return false;
        }
        $this->columns[$table] ??= Schema::getColumnListing($table);

        return in_array($column, $this->columns[$table], true);
    }

    private function iso(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value, config('app.timezone', 'UTC'))->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function displayDate(mixed $value): string
    {
        $date = $this->iso($value);

        return $date ? Carbon::parse($date)->setTimezone($this->timezone())->format('Y/m/d H:i') : 'غير محدد';
    }

    private function timezone(): string
    {
        return $this->hasTable('settings') ? LessonPublicationTime::timezone() : 'Africa/Tripoli';
    }

    private function dateBoundary(string $value, bool $end): string
    {
        $date = Carbon::createFromFormat('!Y-m-d', $value, $this->timezone());

        return ($end ? $date->endOfDay() : $date->startOfDay())->setTimezone(config('app.timezone', 'UTC'))->format($end ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s');
    }

    private function plainText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function normalize(string $value): string
    {
        return Str::lower(str_replace(['أ', 'إ', 'آ', 'ٱ', 'ى', 'ة', 'ـ'], ['ا', 'ا', 'ا', 'ا', 'ي', 'ه', ''], preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value) ?? $value));
    }

    private function normalizedSql(string $column): string
    {
        $sql = 'LOWER(COALESCE('.$column.", ''))";
        foreach (['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ـ' => ''] as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    private function matches(string $label, string $query): bool
    {
        foreach (preg_split('/\s+/u', $this->normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (! str_contains($this->normalize($label), $token)) {
                return false;
            }
        }

        return true;
    }

    private function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function validNumber(mixed $value, float $maximum): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= $maximum;
    }
}
