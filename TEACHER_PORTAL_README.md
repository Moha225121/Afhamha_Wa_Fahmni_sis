 وحدة بوابة المعلم 

## Hex_Af ما تم تنفيذه

### 1) المصادقة والترخيص
- تم تسجيل صلاحيات المعلم في `config/permissions.php`:
  - `attendance.manage`
  - `exams.manage`
  - `grades.manage`
  - `homework.manage`
- تم إضافة Middleware `EnsureUserIsTeacher` (alias: `teacher`) في `bootstrap/app.php`.
- تم إضافة مسارات بوابة المعلم في `routes/teacher.php` وتسجيلها داخل `routes/web.php`.

### 2) البنية والبيانات
- `database/migrations/2026_08_24_000001_create_teacher_academic_schema.php`
  يتضمن الجداول:
  - `exam_questions`
  - `exam_choices`
  - `assignments`
  - `assignment_submissions`
  - `teacher_assignments`
  - `attendance_records`
  - `grades`
  - `grade_sheets`
- تم تحديث `database/seeders/LocalDemoSeeder.php` ليضيف:
  - مستخدم معلم تجريبي
  - صف دراسي
  - مادة
  - طلاب الصف
  - ربط المعلم بالصف والمادة

### 3) بوابة المعلم
Controllers الأساسية:
- `app/Http/Controllers/TeacherPortal/DashboardController.php`
- `app/Http/Controllers/TeacherPortal/StudentController.php`
- `app/Http/Controllers/TeacherPortal/AttendanceController.php`
- `app/Http/Controllers/TeacherPortal/ExamController.php`
- `app/Http/Controllers/TeacherPortal/AssignmentController.php`
- `app/Http/Controllers/TeacherPortal/GradeController.php`
- `app/Http/Controllers/TeacherPortal/ProfileController.php`

### 4) نطاق الوصول للمعلم
- تم إنشاء `app/Http/Controllers/TeacherPortal/Concerns/InteractsWithTeacherScope.php`.
- يمنع المعلم من الوصول إلى الصفوف أو المواد أو الطلاب أو الاختبارات أو الواجبات غير المسندة إليه.
- أي محاولة تجاوز تُرجع `403` أو `404` بشكل آمن.

### 5) واجهة المعلم
- تم إنشاء واجهات داخل `resources/views/teacher/`:
  - `dashboard`
  - `students`
  - `attendance`
  - `exams`
  - `assignments`
  - `grades`
  - `profile`
- تم تعديل `resources/views/teacher/layout.blade.php` بحيث:
  - ترتيب القائمة الجانبية يطابق المطلوب
  - اسم المعلم يفتح الملف الشخصي
  - لا تظهر عناصر غير مرتبطة مباشرة بالمعلم في القائمة الجانبية
- تم ضبط أنماط CSS في `public/css/admin.css` و `public/css/teacher-overrides.css` لتتناسب مع شاشة المعلم.

### 6) الاختبارات
- دعم إنشاء اختبار جديد وحفظه كمسودة.
- دعم المتابعة في المسودة من خلال زر "متابعة" في قائمة الاختبارات.
- دعم تعديل الاختبار قبل التاريخ المحدد له.
- إذا تجاوز التاريخ المحدد، يظهر الاختبار كـ "مكتمل" ولا يسمح بالتعديل.
- عند إنشاء اختبار جديد، يظهر زر "حفظ كمسودة".
- عند تعديل اختبار مجدول مستقبليًا، يظهر زر "تحديث الاختبار".
- تم إضافة إمكانية حذف الاختبار مع تأكيد.
- تم تحسين وصف النموذج ووصف الواجبات بشكل أكثر وضوحًا.

### 7) الدرجات
- تم بناء نموذج درجات: كل صف له جدول درجات خاص به.
- تم دعم إضافة عمود جديد، تعديل اسمه، تعديل وزنه، وحذف العمود.
- يتم حساب المتوسط تلقائيًا حسب أوزان الأعمدة.
- لا يتم عرض طلاب غير المسجلين في الصف المختار.
- يتم حفظ بنية الصف في قاعدة البيانات عبر جدول `grade_sheets`.
- يتم الاحتفاظ بالملف الحالي للصف المختار في `localStorage` عند المتصفح، ومن ثم حفظه في DB عند الحفظ.

### 8) الواجبات
- إنشاء واجبات جديدة.
- تعديل واجب موجود.
- رفع ملف مرفق عند الحاجة.
- متابعة التسليمات وتسجيلها.
- إدخال الدرجات لكل طالب وتسليمها.
- حفظ حالة التسليم والدرجة بشكل منفصل.

### 9) الحضور
- تسجيل حضور الطلاب حسب الصف والتاريخ.
- حفظ السجلات في `attendance_records`.
- دعم اختيار الصف وتاريخ الحضور مباشرة من شاشة المعلم.

## بيانات الدخول التجريبية بعد التشغيل
- المعلم: `teacher@example.test` / `password123`

## خطوات التشغيل المحلي
```bash
composer install
cp .env.example .env   # إن لم يكن موجودًا
php artisan key:generate
php artisan migrate:fresh
php artisan db:seed --class=Database\\Seeders\\LocalDemoSeeder
php artisan storage:link
php artisan serve
```

## السيناريو الكامل للاختبار اليدوي
1. تسجيل الدخول كمعلم → تحويل تلقائي إلى `/teacher/dashboard`.
2. `/teacher/students` → تظهر فقط الطلاب المربوطون بالصف المسند للمعلم.
3. `/teacher/attendance` → اختر التاريخ والصف → سجّل الحضور → يتم حفظ السجلات في `attendance_records`.
4. `/teacher/exams/create` → أنشئ اختبارًا جديدًا، احفظه كمسودة، ثم عد إليه عبر "متابعة" لإضافة الأسئلة أو تعديلها.
5. `/teacher/exams` → لاحظ أن المسودة تظهر بـ "متابعة"، بينما الاختبارات المجدولة المستقبلية تظهر بـ "تعديل"، وبعد تاريخها تظهر "مكتمل".
6. `/teacher/grades` → اختر الصف → أضف/عدّل الأعمدة والأوزان → أدخل الدرجات → احفظ.
7. `/teacher/assignments/create` → أنشئ واجبًا مع وصف، موعد تسليم، ودرجة قصوى.
8. `/teacher/assignments/{id}/submissions` → سجل التسليمات والدرجات لكل طالب.

## قيود متعمدة
- لا توجد شاشة أداء اختبار من جهة الطالب في هذه المرحلة.
- لا توجد تقارير إدارية شاملة ضمن نطاق هذه الوحدة.
- تعديل الأسئلة في الاختبار يُسمح فقط قبل موعده أو في حالة المسودة، لحماية سلامة النتائج عند النشر.

## ملاحظات إضافية
- تم تحسين قابلية التمرير داخل الجداول الواسعة لتسهيل القراءة دون الحاجة لتصغير حجم الشاشة.
