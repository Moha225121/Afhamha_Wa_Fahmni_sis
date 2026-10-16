<?php

namespace App\Services;

use App\Models\Classroom;
use Illuminate\Support\Collection;

class ClassroomOrdering
{
    /** @param Collection<int, Classroom> $classrooms */
    public function sort(Collection $classrooms): Collection
    {
        return $classrooms->sort(function (Classroom $left, Classroom $right): int {
            foreach ([$this->grade($left) <=> $this->grade($right), $this->track($left) <=> $this->track($right)] as $comparison) {
                if ($comparison !== 0) return $comparison;
            }
            return strnatcasecmp($this->normalize($left->section ?? ''), $this->normalize($right->section ?? ''))
                ?: strnatcasecmp($this->normalize($left->name), $this->normalize($right->name))
                ?: $left->id <=> $right->id;
        })->values();
    }

    private function grade(Classroom $classroom): int
    {
        $name = $this->normalize($classroom->name);
        $description = $this->normalize($classroom->stage.' '.$classroom->name);
        if (preg_match('/تمهيدي|روضه|kg|kindergarten/u', $description)) return 0;
        $secondary = (bool) preg_match('/ثانوي|علمي|ادبي|secondary/u', $description);
        if (preg_match('/(?<![0-9])(1[0-2]|[1-9])(?![0-9])/u', $name, $match)) {
            $grade = (int) $match[1];
            return $secondary && $grade <= 3 ? $grade + 9 : $grade;
        }
        $ordinals = [
            'الثاني عشر' => 12, 'ثاني عشر' => 12, 'الحادي عشر' => 11, 'حادي عشر' => 11,
            'العاشر' => 10, 'عاشر' => 10,
            'الاول' => 1, 'اول' => 1, 'الاولي' => 1, 'اولي' => 1,
            'الثاني' => 2, 'ثاني' => 2, 'الثانيه' => 2,
            'الثالث' => 3, 'ثالث' => 3, 'الثالثه' => 3,
            'الرابع' => 4, 'رابع' => 4, 'الرابعه' => 4,
            'الخامس' => 5, 'خامس' => 5, 'الخامسه' => 5,
            'السادس' => 6, 'سادس' => 6, 'السادسه' => 6,
            'السابع' => 7, 'سابع' => 7, 'السابعه' => 7,
            'الثامن' => 8, 'ثامن' => 8, 'الثامنه' => 8,
            'التاسع' => 9, 'تاسع' => 9, 'التاسعه' => 9,
        ];
        foreach ($ordinals as $ordinal => $grade) {
            if (preg_match('/(?:^|[\s_-])'.preg_quote($ordinal, '/').'(?=$|[\s_-])/u', $name)) {
                return $secondary && $grade <= 3 ? $grade + 9 : $grade;
            }
        }
        return 99;
    }

    private function track(Classroom $classroom): int
    {
        $text = $this->normalize($classroom->name.' '.$classroom->stage);
        return str_contains($text, 'علمي') ? 1 : (str_contains($text, 'ادبي') ? 2 : 0);
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        return mb_strtolower(strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));
    }
}
