<?php

return ['roles' => ['admin' => ['*'], 'financial_officer' => ['finance.view', 'finance.pay', 'finance.reports'], 'supervisor' => ['daily-attendance.manage', 'student-followup.manage', 'guardian-calls.manage', 'attendance-reports.view'], 'teacher' => ['exams.manage', 'grades.manage', 'homework.manage', 'lessons.manage'], 'student' => [], 'parent' => []]];
