<?php

return [
    'school_name' => 'مدرسة النسبية',
    'school_english_name' => 'Al Nisbiya',
    'school_code' => 'AWF',
    'student_number_pattern' => 'ST-{YEAR}-{0001}',
    'student_start' => 1,
    'student_digits' => 4,
    'student_reset_yearly' => true,
    'teacher_number_pattern' => 'TCH-{YEAR}-{0001}',
    'teacher_start' => 1,
    'teacher_digits' => 4,
    'teacher_reset_yearly' => false,
    'student_email_pattern' => '{FIRST_NAME}_{ACADEMIC_NUMBER}@awf_{SCHOOL_NAME}.com',
    'teacher_email_pattern' => '{FIRST_NAME}_{TEACHER_NUMBER}@awf_{SCHOOL_NAME}.com',
    'student_auto_email' => true,
    'teacher_auto_email' => true,
    'student_finance_visible' => false,
];
