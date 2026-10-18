@props(['content' => ''])
@once
<style>
.assignment-content { overflow-wrap: anywhere; line-height: 1.9; color: inherit; }
.assignment-content p { margin: 0 0 .8em; white-space: pre-line; }
.assignment-content p:last-child { margin-bottom: 0; }
.assignment-content ul, .assignment-content ol { margin: .6em 0; padding-inline-start: 1.6em; }
.assignment-content ul { list-style: disc; }
.assignment-content ol { list-style: decimal; }
.assignment-content li { margin-bottom: .35em; }
.assignment-content pre { white-space: pre-wrap; direction: ltr; text-align: start; }
.assignment-content table { display: block; max-width: 100%; overflow-x: auto; border-collapse: collapse; }
.assignment-content th, .assignment-content td { border: 1px solid #dce5eb; padding: .4em .65em; }
.assignment-content blockquote { margin: .8em 0; padding-inline-start: 1em; border-inline-start: 3px solid #dce5eb; }
</style>
@endonce
<div class="assignment-content">{{ app(\App\Services\AssignmentContentRenderer::class)->render($content) }}</div>
