<?php

namespace App\Services;

use App\Models\LibraryResource;

class LibraryBookNaming
{
    public function metadata(string $filename, array $data = []): array
    {
        $text = LibraryResource::normalizeSearch($filename.' '.($data['title'] ?? '').' '.($data['category'] ?? ''));
        $subject = trim($data['subject_name'] ?? '');
        if ($subject === '') {
            foreach ([
                'اللغة الإنجليزية' => ['english', 'primary', 'preparatory', 'secondary', 'انجليزي', 'انجليز'],
                'التربية الإسلامية' => ['اسلام'], 'التربية الوطنية' => ['وطني'],
                'الثقافة المهنية' => ['مهني'], 'تقنية المعلومات' => ['تقنية المعلومات'],
                'الحاسوب' => ['حاسوب'], 'الرياضيات' => ['رياضيات'],
                'الفيزياء' => ['فيزياء', 'فيزيا', 'ميكانيكا'], 'الكيمياء' => ['كيمياء', 'كيميا'],
                'الأحياء' => ['احياء'], 'العلوم' => ['علوم'], 'الإحصاء' => ['احصاء'],
                'علم الاجتماع' => ['علم اجتماع', 'علم الاجتماع'], 'علم النفس' => ['علم النفس', 'علم نفس'],
                'الفلسفة' => ['فلسف'], 'التاريخ' => ['تاريخ'], 'الجغرافيا' => ['جغراف'],
                'الاجتماعيات' => ['اجتماعيات'],
                'اللغة العربية' => ['عربي', 'نحو', 'مطالعة', 'ادب', 'بلاغة', 'املاء', 'لغوية'],
            ] as $label => $patterns) {
                foreach ($patterns as $pattern) {
                    if (str_contains($text, LibraryResource::normalizeSearch($pattern))) {
                        $subject = $label;
                        break 2;
                    }
                }
            }
        }
        $type = $data['book_type'] ?? null;
        if (str_contains($text, 'تقويم') || str_contains($text, 'تقييم')) {
            $type = 'assessment';
        } elseif (str_contains($text, 'دليل المعلم') || str_contains($text, 'teacher') || preg_match('/(?:^|[^a-z])tb(?:[^a-z]|$)/i', $filename)) {
            $type = 'teacher_guide';
        } elseif (! $type) {
            $type = match (true) {
                str_contains($text, 'خط') => 'handwriting',
                str_contains($text, 'تدريب'), (bool) preg_match('/(?:^|[^a-z])wb(?:[^a-z]|$)/i', $filename) => 'workbook',
                str_contains($text, 'نشاط'), str_contains($text, 'انشطه'), (bool) preg_match('/(?:^|[^a-z])ab(?:[^a-z]|$)/i', $filename) => 'activity',
                default => 'textbook',
            };
        }
        $term = $data['term'] ?? null;
        if (! $term && preg_match('/(الفصل|الجزء)(?: الدراسي)?\s*(الاول|الثاني)/u', $text, $match)) {
            $term = $match[1].' '.($match[2] === 'الاول' ? 'الأول' : 'الثاني');
        }
        $grades = array_values(array_unique(array_map('intval', $data['grade_levels'] ?? [])));
        sort($grades);
        $stage = $data['education_stage'] ?? null;
        if (! $stage && $grades !== []) {
            $stage = max($grades) <= 9 ? 'أساسي' : (min($grades) >= 10 ? 'ثانوي' : null);
        }

        return [
            'subject_name' => $subject ?: 'عام',
            'grade_levels' => $grades,
            'education_stage' => $stage,
            'book_type' => $type,
            'term' => $term,
            'audience' => in_array($type, ['teacher_guide', 'assessment'], true) ? 'teachers' : ($data['audience'] ?? 'all'),
            'title' => trim($data['title'] ?? '') ?: (($subject ?: 'كتاب تعليمي').' — '.config('library.book_types.'.$type, 'كتاب').($term ? ' — '.$term : '')),
        ];
    }
}
