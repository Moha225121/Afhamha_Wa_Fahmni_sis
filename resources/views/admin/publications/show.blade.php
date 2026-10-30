@extends('admin.layout')
@section('title', 'تفاصيل المنشور')
@section('subtitle', 'المحتوى الكامل وبيانات النشر كما سجلها المعلم.')
@section('actions')<a class="btn secondary" href="{{ $backUrl ?? route('admin.publications.index', ['view' => 'feed']) }}">العودة إلى المنشورات</a>@endsection

@section('content')
<link rel="stylesheet" href="{{ asset('css/admin-publications.css') }}?v=20261024-1">
@php
    $dateText = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->timezone('Africa/Tripoli')->format('Y/m/d · H:i') : 'غير محدد';
    $contentLabels = ['lesson' => 'شرح الدرس', 'assignment' => 'تفاصيل الواجب', 'exam' => 'وصف الاختبار', 'grade' => 'ملاحظات السجل'];
    $sizeText = fn ($size) => $size ? ($size >= 1048576 ? number_format($size / 1048576, 1).' MB' : number_format($size / 1024).' KB') : null;
    $numberText = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $hasGradeTotals = !empty($post['grade_rows']) && collect($post['grade_rows'])->every(fn ($row) => array_key_exists('total', $row));
@endphp
<section class="ap-detail ap-kind-{{ $post['type'] }}" data-publication-detail data-publication-key="{{ $post['key'] }}" data-publication-version="{{ $post['fingerprint'] ?? '' }}" data-check-url="{{ route('admin.publications.show', ['kind' => $post['type'], 'id' => $post['id']]) }}" aria-label="محتوى المنشور">
    <div class="ap-revision-notice" data-publication-revision hidden role="status"><span>عُدّل هذا المنشور. حدّث الصفحة للاطلاع على آخر نسخة.</span><a class="ap-button ap-button-primary" href="{{ request()->fullUrl() }}">عرض التحديث</a></div>
    <article class="ap-detail-heading">
        <div class="ap-post-top"><span class="ap-type-pill"><span class="ap-kind-mark" aria-hidden="true"></span>{{ $post['type_label'] }}</span><span class="ap-status" data-status="{{ $post['status'] }}">{{ $post['status_label'] }}</span></div>
        <h2>{{ $post['title'] }}</h2>
        <div class="ap-post-byline"><span class="ap-teacher"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 21v-3a7 7 0 0 1 14 0v3"/></svg>{{ $post['teacher']['name'] }}</span>@if($post['subject'])<span class="ap-byline-divider" aria-hidden="true">·</span><span>{{ $post['subject']['name'] }}</span>@endif</div>
        <div class="ap-audience">@foreach($post['classrooms'] as $classroom)<a href="{{ route('admin.publications.index', ['classroom_id' => $classroom['id'], 'view' => 'feed']) }}">{{ $classroom['label'] }}</a>@endforeach</div>
        <div class="ap-detail-dates"><div><span>{{ $post['published_label'] ?? ($post['status'] === 'draft' ? 'تاريخ الإنشاء' : 'موعد النشر') }}</span><time datetime="{{ $post['published_at'] }}">{{ $dateText($post['published_at']) }}</time></div><div><span>آخر تعديل</span><time datetime="{{ $post['updated_at'] }}">{{ $dateText($post['updated_at']) }}</time></div></div>
    </article>

    @if(!empty($post['metadata']))
        <section class="ap-panel" aria-labelledby="publication-data-heading"><div class="ap-panel-heading"><h3 id="publication-data-heading">بيانات المنشور</h3></div><dl class="ap-metadata">@foreach($post['metadata'] as $datum)<div><dt>{{ $datum['label'] }}</dt><dd>{{ $datum['value'] ?? '—' }}</dd></div>@endforeach</dl></section>
    @endif

    @if(trim($post['content'] ?? '') !== '')
        <section class="ap-panel" aria-labelledby="publication-content-heading"><div class="ap-panel-heading"><h3 id="publication-content-heading">{{ $contentLabels[$post['type']] ?? 'المحتوى' }}</h3></div><div class="ap-content">{!! app(\App\Support\RichText::class)->sanitize($post['content']) !!}</div></section>
    @endif

    @if($post['type'] === 'grade')
        <section class="ap-panel ap-grades" aria-labelledby="publication-grades-heading">
            <div class="ap-panel-heading"><h3 id="publication-grades-heading">الدرجات المسجلة</h3><span>{{ count($post['grade_rows'] ?? []) }} طالب</span></div>
            @if(!empty($post['subject_context']))<p class="ap-section-note">مواد المعلم المرتبطة بهذا الصف: {{ collect($post['subject_context'])->pluck('name')->join('، ') }}</p>@endif
            @if(!empty($post['grade_rows']))
                <div class="ap-grade-table-wrap" role="region" aria-label="درجات الطلاب" tabindex="0">
                    <table class="ap-grade-table">
                        <thead><tr>
                            <th scope="col">اسم الطالب</th>
                            @foreach($post['grade_columns'] as $column)
                                <th scope="col"><span>{{ $column['label'] }}</span>
                                    @if($column['max_score'] !== null)
                                        <small>من {{ $numberText($column['max_score']) }}</small>
                                    @endif
                                </th>
                            @endforeach
                            @if($hasGradeTotals)
                                <th scope="col">المجموع</th>
                            @endif
                        </tr></thead>
                        <tbody>
                            @foreach($post['grade_rows'] as $row)
                                <tr>
                                    <th scope="row">{{ $row['name'] }}</th>
                                    @foreach($post['grade_columns'] as $column)
                                        <td>{{ $numberText($row['scores'][$column['key']]['score'] ?? null) }}</td>
                                    @endforeach
                                    @if($hasGradeTotals)
                                        <td><strong>{{ $numberText($row['total']) }}</strong>
                                            @if(isset($row['max_total']))
                                                <small>من {{ $numberText($row['max_total']) }}</small>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else<p class="ap-section-note">لا توجد درجات مسجلة في هذا السجل.</p>@endif
        </section>
    @endif

    @if(!empty($post['questions']))
        <section class="ap-panel" aria-labelledby="publication-questions-heading">
            <div class="ap-panel-heading"><h3 id="publication-questions-heading">أسئلة الاختبار</h3><span>{{ count($post['questions']) }} سؤال</span></div>
            <ol class="ap-questions">
                @foreach($post['questions'] as $question)
                    <li>
                        <div class="ap-question-heading"><span>السؤال {{ $loop->iteration }}</span>
                            @if(isset($question['score']))
                                <span>{{ $numberText($question['score']) }} درجة</span>
                            @endif
                        </div>
                        <p>{{ $question['text'] }}</p>
                        @if(!empty($question['options']))
                            <ul class="ap-question-options">
                                @foreach($question['options'] as $option)
                                    <li>{{ is_array($option) ? ($option['text'] ?? $option['label'] ?? '') : $option }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <section class="ap-panel" aria-labelledby="publication-attachments-heading">
        <div class="ap-panel-heading"><h3 id="publication-attachments-heading">المرفقات</h3><span>{{ count($post['attachments'] ?? []) }} ملف</span></div>
        <div class="ap-files">
            @forelse($post['attachments'] ?? [] as $attachment)
                @php
                    $fileUrl = route('admin.publications.download', ['kind' => $post['type'], 'id' => $post['id'], 'attachment' => $attachment['id']]);
                    $mime = (string) ($attachment['mime_type'] ?? '');
                    $previewUrl = $mime === 'application/pdf' ? $fileUrl.'?preview=1' : $fileUrl;
                    $canPreview = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'video/mp4', 'audio/mpeg', 'audio/mp4', 'audio/ogg'], true);
                @endphp
                <article class="ap-file">
                    <div class="ap-file-row"><span class="ap-file-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 3H6v18h12V7zM14 3v5h5M9 13h6M9 17h6"/></svg></span><div class="ap-file-name"><strong>{{ $attachment['name'] }}</strong>@if($sizeText($attachment['size'] ?? null))<span dir="ltr">{{ $sizeText($attachment['size']) }}</span>@endif</div><a class="ap-button ap-button-soft" href="{{ $fileUrl }}?download=1">تنزيل</a></div>
                    @if($canPreview)
                        <details class="ap-file-preview"><summary>معاينة الملف <span aria-hidden="true">⌄</span></summary><div class="ap-file-preview-body">@if(str_starts_with($mime, 'image/'))<img src="{{ $fileUrl }}" alt="{{ $attachment['name'] }}" loading="lazy">@elseif($mime === 'application/pdf')<iframe src="{{ $previewUrl }}" title="{{ $attachment['name'] }}" loading="lazy"></iframe>@elseif(str_starts_with($mime, 'video/'))<video controls preload="none" src="{{ $fileUrl }}"></video>@else<audio controls preload="none" src="{{ $fileUrl }}"></audio>@endif<a class="ap-open-file" href="{{ $previewUrl }}" target="_blank" rel="noopener">فتح في نافذة جديدة ↗</a></div></details>
                    @endif
                </article>
            @empty
                <p class="ap-section-note">لا توجد ملفات مرفقة بهذا المنشور.</p>
            @endforelse
        </div>
    </section>
</section>
@endsection
@push('scripts')
<script type="module" src="{{ asset('js/admin-publications.js') }}?v=20261024-1"></script>
@endpush
