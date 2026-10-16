<?php

namespace App\Services;

class SubjectDisplayName
{
    /** Translate familiar imported subject names without changing stored school data. */
    public static function arabic(?string $name): string
    {
        $name = trim($name ?? '');
        if ($name === '') {
            return 'مادة غير محددة';
        }

        $names = [
            'Islamic Education' => 'التربية الإسلامية', 'Islamic Studies' => 'التربية الإسلامية',
            'Religious Education' => 'التربية الإسلامية', 'Social Studies' => 'الدراسات الاجتماعية',
            'Computer Science' => 'الحاسوب', 'Information Technology' => 'تقنية المعلومات',
            'Physical Education' => 'التربية البدنية', 'Art Education' => 'التربية الفنية',
            'Arabic Language' => 'اللغة العربية', 'English Language' => 'اللغة الإنجليزية',
            'French Language' => 'اللغة الفرنسية', 'General Science' => 'العلوم العامة',
            'Mathematics' => 'الرياضيات', 'Chemistry' => 'الكيمياء', 'Geography' => 'الجغرافيا',
            'Statistics' => 'الإحصاء', 'Mechanics' => 'الميكانيكا', 'Computing' => 'الحاسوب',
            'Computer' => 'الحاسوب', 'Physics' => 'الفيزياء', 'Biology' => 'الأحياء',
            'Science' => 'العلوم', 'History' => 'التاريخ', 'Arabic' => 'اللغة العربية',
            'English' => 'اللغة الإنجليزية', 'French' => 'اللغة الفرنسية', 'Maths' => 'الرياضيات',
            'Math' => 'الرياضيات', 'Quran' => 'القرآن الكريم', 'ICT' => 'تقنية المعلومات',
            'Art' => 'التربية الفنية', 'PE' => 'التربية البدنية',
        ];

        foreach ($names as $english => $arabic) {
            if (preg_match('/^'.preg_quote($english, '/').'(?=$|[\s\-_:()\d])/iu', $name, $match)) {
                $suffix = trim(substr($name, strlen($match[0])));
                $suffix = preg_replace(['/\bGrade\b/i', '/\bYear\b/i', '/\bPrimary\b/i', '/\bSecondary\b/i'], ['الصف', 'السنة', 'ابتدائي', 'ثانوي'], $suffix);

                return trim($arabic.' '.$suffix);
            }
        }

        return $name;
    }
}
