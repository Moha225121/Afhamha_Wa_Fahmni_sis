@php
    $instructions = trim((string) $assignment->instructions);
    $instructions = $instructions !== '' ? $instructions : trim((string) $assignment->description);
    $safeInstructions = $instructions !== ''
        ? (preg_match('/<\/?[a-z][^>]*>/i', $instructions) || preg_match('/&(?:amp;)?lt;\/?[a-z]/i', $instructions)
            ? app(\App\Support\RichText::class)->sanitize($instructions)
            : nl2br(e($instructions)))
        : '<p class="muted-line">لا توجد تعليمات إضافية.</p>';
@endphp
<div class="homework-instructions" dir="auto">{!! $safeInstructions !!}</div>
