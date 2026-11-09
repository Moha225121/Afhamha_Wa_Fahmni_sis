# دفعة العلاقات والاستيراد والواجهة

التاريخ: 2026-11-08. النسخة المستهدفة بعد تأكيد المستخدم: مدرسة النسبية، جذر المستودع فقط. المرجع الأساسي `1a5cfe27c72e38acd05c837682a3f29b0d7b3402`، الفرع `codex/qa-release-readiness-20261108`، التغييرات غير ملتزم بها. لا تعديل على نسخة `nasr-al-daem-school` ولا نشر أو اتصال إنتاج.

## المصادر والبيئة

- تقرير الإدارة الأصلي `HEXA Admin portal QA Report.pdf`: الصفحات المحددة أدناه؛ استُخدم الاستخراج المنقح الذي جهزه المنسق، وتبقى الصور ومراجعتها البصرية في سجل المنسق.
- `QA ESHTAWI.docx`: الفقرتان 2 و5 للوصول السريع للدروس وزر الرجوع، بدون اختراع رقم صفحة.
- تقرير المعلم `____.docx.pdf`: الصفحة 3 لكود المادة. تصنيفه الأصلي «سليم» مع ملاحظة تحسين، وليس خطأ خادم.
- تقرير ولي الأمر `QA Report -    _261103_164551.pdf`: الصفحة 2 لاسم النسخة وأيقونتها؛ الهدف مؤكد من المستخدم لاحقًا.
- SQLite `:memory:`، PHP 8.4.21، اختبارات HTTP Laravel فعلية عبر PHPUnit. بعد توفر المشغّل استُخدم `scripts/qa/run-isolated.ps1` مع تخزين جديد لكل تشغيل، إعدادات ومفاتيح إرسال معزولة ومنع طلبات HTTP الخارجية غير المزيفة. لا اختبار هاتف فعلي ولا متصفح في هذه الدفعة؛ ينفذ المنسق اختبار المتصفح منفصلًا.
- البيانات اصطناعية: صفان دراسيان وشعب A/B، مواد `QA-A` و`QA-B` و`QA-NAV`، طالبا `QA-CHILD-*`، ولي أمر `qa-parent@example.test`، معلم وإدارة آخران لاختبار منع الوصول. لا نسخ لأي حساب من المصادر.

## سجل البنود

| المعرف | المصدر/تصنيفه | معيار القبول والخطوات والنتيجة | الحالة المحلية والدليل | الحدود |
|---|---|---|---|---|
| QA-008 | الإدارة ص4، عطل 500 | من الإدارة افتح بطاقة طالب منفردة وPDF وبطاقات صف. قبل الإصلاح فشل `SchoolBrandingTest` بخطأ `Route [student.card.verify] not defined`. استُعيد المسار الموقع الموجود استخدامه في `AccountNumberingController` مع `signed` وحد معدل؛ نجحت البطاقة وQR وPDF والصف المصفى. فشل الرابط غير الموقع والمعدل والمنتهي بـ403. | FIXED_LOCAL_TESTED؛ `QaRelationsInterfaceTest::test_student_cards_render_filtered_students_and_require_valid_signed_verification` و`SchoolBrandingTest` | طباعة ورقية فعلية ومراجعة التخطيط البصري عند المنسق/QA؛ لا يعادل PDF ناجح طباعة على جهاز. |
| QA-009 | الإدارة ص31-32، رفض/500 هاتف | أرسل نموذج صف من عميل HTTP يحمل User-Agent اصطناعيًا للهاتف، ثم أعد فتح تعديل الصف وتحقق من العلاقات المحفوظة؛ نجحت العملية دون500. لا مسار خادم خاص بالهاتف في الشيفرة. | NOT_REPRODUCED؛ اختبار علاقات الصف | User-Agent ليس اختبار متصفح هاتف؛ يلزم إعادة الاختبار على العرض والهاتف المذكورين. لا يدعى أن سبب عطل التقرير مثبت. |
| QA-010 | الإدارة ص8، اختيار ابن واحد؛ نتيجة أخرى متعارضة محفوظة في السجل العام | إنشاء ولي أمر بطفلين في صفين مختلفين، إعادة التحميل ثم إزالة أحدهما. علاقات الخادم كانت تحفظ الاثنين؛ الواجهة تستخدم select multiple المستبعد من enhanced-selects. استُبدل بقراءة HTML أصلية ذات مربعات اختيار قابلة للمس/Space وبحث لا يحذف الاختيارات المخفية. | FIXED_LOCAL_TESTED؛ `test_guardian_can_link_two_children_then_remove_one` | تحقق اللمس ولوحة المفاتيح في المتصفح منفصل. |
| QA-011 | الإدارة ص10، إضافة/إزالة مواد | إنشاء صف بمادتين ثم إعادة تحميله وإزالة الأولى؛ حفظ العلاقات صحيح، استبدال select multiple بالمكون نفسه مع كود المادة. | FIXED_LOCAL_TESTED؛ `test_class_and_subject_relations_are_saved_reloaded_and_removed` | لا تغيير بنية قاعدة البيانات. |
| QA-012 | الإدارة ص12، ربط مادة بفصلين | ربط المادة بصفين ثم إلغاء جميع الروابط وإعادة الاستعلام؛ صفر روابط بعد الإزالة. عرض اسم الصف والشعبة والسنة لتجنب اللبس. | FIXED_LOCAL_TESTED؛ اختبار العلاقات نفسه، سيناريو مستقل | اللمس عند المنسق. |
| QA-013 | الإدارة ص10، قالب يحتاج تغيير الاسم إلى الصف | تنزيل القالب الفعلي ثم إعادة رفعه دون تغيير. فشل قبل الإصلاح لأن القالب يحمل `الاسم` والقارئ يقبل `name/الصف` فقط. أضيف alias `الاسم` مع إبقاء التوافق؛ إعادة الاستيراد لا تكرر الصف. صف ناقص المرحلة يرفض الملف ويرد المعاملة كاملة. | FIXED_LOCAL_TESTED؛ `test_class_template_round_trips_without_renaming_headers` و`test_class_import_rejects_missing_required_values_atomically` | التحقق المحدد على CSV الناتج من القالب؛ لا ادعاء اختبار كل برامج XLSX. |
| QA-025 | ESHTAWI فقرة2، تحسين | صفحة قائمة الدروس وتفاصيلها تتضمن فهرسًا على اليمين في RTL، ينهار إلى عمود قابل للطي على الهاتف؛ روابط مباشرة لكل درس مسموح. الاستعلام يحتفظ بقيود المادة والصف والنشر والوحدة. الاختبار أخفى المسودة والمستقبلي وصفًا آخر ومنع فتح معرفه. | FIXED_LOCAL_TESTED؛ `test_lesson_quick_navigation_contains_only_accessible_published_lessons` | التخطيط البصري الفعلي عند المنسق. |
| QA-026 | ولي الأمر ص2، هوية النسخة | المستخدم أكد الجذر/النسبية؛ لم تُستبدل هوية مدارس أخرى. أعيدت نقاط أيقونات الشعار المعتمد المفقودة، واسم manifest المختصر يستعمل اسم المدرسة بدل «ولي الأمر»، والافتراضي النسبية. أيقونتا manifest الافتراضيتان فعليًا192/512 بدل وصف ملف واحد بحجمين. | NEEDS_DEVICE_VERIFICATION؛ `SchoolBrandingTest::test_parent_install_metadata_names_target_school_and_declares_actual_icon_sizes` | اسم وأيقونة تطبيق مثبت سابقًا وتحديث كاش جهاز فعلي لم يُختبرا. مسار الشعار المخصص الموجود ظل محفوظًا. |
| QA-027 | الإدارة ص4، تحسين ترتيب وشعب | فلتر الطلاب يرتب بالأرقام الدراسية الموجودة في `LibraryCatalogService::gradeForClassroom` ثم الشعبة ثم الاسم والسنة، ويظهر الشعبة والسنة. الأولA ثمB قبل السادسB في اختبار. | FIXED_LOCAL_TESTED؛ `test_students_filter_has_academic_order_sections_and_grouped_search` | الأسماء غير المعروفة توضع بعد الصفوف المعروفة؛ لم تُخترع سياسة ترتيب لاسم غير معروف. |
| QA-028 | الإدارة ص15، طلب مرشحات | عنوان، صف/شعبة، مادة، معلم، حالة الاختبار الفعلية؛ إعادة ضبط، حفظ query عبر الترقيم، قيم غير صالحة422. الحالة scheduled لا تضم المستحق الذي أصبح published. | FIXED_LOCAL_TESTED؛ `test_exam_filters_and_grade_filters_preserve_scope` و`test_filters_validate_values_and_preserve_pagination_parameters` | اختيرت الحقول الموجودة فعليًا، لم تتغير قواعد أداء الاختبار. |
| QA-029 | الإدارة ص16، طلب مرشحات | الصف/الشعبة والسنة والمادة. ملخص درجات كشوف المعلمين يحترم المادة؛ أضيف ترقيم20 صفًا وحفظ query وإعادة ضبط. | FIXED_LOCAL_TESTED؛ اختبار المرشحات | حساب المتوسط الأصلي محفوظ؛ الاختبار لا يثبت سياسة أكاديمية جديدة. |
| QA-030 | الإدارة ص22، طلب/خلل فلترة | بحث الاسم/البريد مركب مع الدور والحالة. ثبت أن OR غير مجمع يسمح بمرور نتائج من أدوار أخرى؛ جمعت شروط البحث وأضيفت الحالة وإعادة الضبط وحفظ اختيار الدور. 21 نتيجة موزعة20/1 مع query محفوظ. | FIXED_LOCAL_TESTED؛ `test_users_search_composes_with_role_status_and_pagination` و`test_filters_validate_values_and_preserve_pagination_parameters` | صلاحيات الإدارة في الخادم قائمة؛ أضيف موظف المالية بالتنسيق مع دفعة الصلاحيات. |
| QA-031 | الإدارة ص28، طلب فلترة مع بحث موجود | بحث خادم في جميع محادثات المستخدم ومرشحات الصف والحالة، مع إبقاء البحث السريع الموجود داخل الصفحة. يبدأ الاستعلام من inbox المقيد بالمشاركين؛ محادثة إدارة أخرى مخفية وممنوعة404 حتى مع معرف صحيح. | FIXED_LOCAL_TESTED؛ `test_admin_chat_filters_never_reveal_another_admins_conversation` | لا توسيع لصلاحية قراءة محادثات غير مشارك بها. |
| QA-032 | الإدارة ص25، لون لا يعمل | اللون المرسل كان exclude ثم يستبدل بالثابت، وview composer يعيد الثابت. صار الحفظ يقبل palette المعروضة فقط ويطبق CSS متغيرًا على البوابات المسجل دخولها بعد إعادة التحميل. إدخال CSS تعسفي مرفوض ولا يغير القيمة المحفوظة؛ الشعار/شاشة الدخول ثابتان. | FIXED_LOCAL_TESTED؛ `SchoolBrandingTest::test_saving_settings_keeps_logo_and_applies_selected_portal_color` | عدل assertion قديم يمنع تغيير اللون لأنه يناقض طلب المستخدم الصريح وواجهة الاختيار القائمة؛ بقيت اختبارات ثبات الشعار ومنع رفع بديل. |
| QA-033 | الإدارة ص30، تداخل الشعار والقائمة | زر إغلاق44px أعلى القائمة، مساحة مستقلة للشعار، overlay، Escape وإرجاع التركيز لزر الفتح؛ aria-expanded يعكس الحالة. | FIXED_LOCAL_TESTED؛ HTML ضمن اختبار المحادثات | إثبات عدم تداخل360/390/768/1440 يحتاج سجل المتصفح، لا يثبته HTTP. |
| QA-034 | الإدارة ص32، تضخم الأيقونات | HTML قبل الإصلاح طبع `@stack('styles')` حرفيًا بسبب توالي `@endif@stack`؛ CSS المحادثات لم يصل رغم تعريف23px الصحيح. فصلت التوجيهات؛ اختبار استجابة يؤكد تحميل CSS وعدم وجود directive خام. | FIXED_LOCAL_TESTED؛ اختبار المحادثات | لا ادعاء السبب من الصورة وحدها؛ ثبت من استجابة HTTP. الأبعاد المحسوبة الفعلية عند المنسق. |
| QA-035 | ESHTAWI فقرة5، تنسيق الرجوع | الزر العام الذي يولده `portal-system.js` كان بلاCSS؛ أثبتت لقطة المتصفح استمرار الشكل الأصلي بعد تعديل زر المحادثة وحده. أضيف تنسيق `.portal-back-button` بارتفاع44px/ألوان الهوية/hover مع حفظ سلوك history/fallback نفسه. زر المحادثة أيضًا منسق. | FIXED_LOCAL_TESTED؛ اختبارات SchoolChat + التنقل، ثم مراجعة متصفح المنسق | لم يعد تعديل زر المحادثة وحده يعد إغلاقًا؛ أُعيد فحص الأصل بعد ملاحظة الصورة. |
| QA-036 | المعلم ص3، ملاحظة تحسين | خيار مادة نموذج إنشاء الدرس الحالي يعرض name-code بالفعل. GET فعلي بحساب معلم مكلف يعرض `QA-NAV` ضمن النموذج. | ALREADY_FIXED_VERIFIED؛ اختبار التنقل/نموذج المعلم | لم تُنسب إضافة كود موجودة إلى هذه الدفعة؛ أضيف أيضًا للمحددات الجديدة ضمن سياقها. |

## النتائج الفعلية المرحلية

1. قبل التعديل: `QaRelationsInterfaceTest`، 8 اختبارات، 26 assertion، فشل6/نجح2. أعيد إنتاج CSV وواجهة العلاقات ودمج المرشحات ومرشحات الاختبار والمحادثة.
2. بعد الدفعة الأولى: المشغّل المعزول على الملف نفسه، نجح8/8 و49 assertion. JUnit المؤقت: `tmp/qa-runs/ae468a31593b42fa83a7767e0f2b0bf4/phpunit.xml`.
3. فحص SchoolBranding بعد اللون وقبل إعادة المسارات:6 اختبارات،4 نجاح/2 فشل،24 assertion. الفشلان: أيقونة404 وبطاقات500 بسبب غياب مسار التحقق الموقع. هذا إثبات سبب QA008، وليس فشلًا نُسب إلى إصلاح اللون.
4. فحص موسع بعد إعادة المسارات والتنقل:41 اختبارًا،39 نجاح/2 فشل،346 assertion. الفشلان من `SchoolAccountNumberingTest`: نطاق البريد المتوقع `awf_` بينما إعداد المشروع الحالي `nbs_`. لم تعدل تلك الاختبارات أو إعداد البريد؛ رُفع الفرق للمنسق. المرجع `tmp/qa-runs/d6c344e9dd1d4858998c84afe04cd203/phpunit.xml`.
5. محاولة filter تحتوي `|` تعذرت قبل PHPUnit بسبب wrapper `php.bat`؛ أصلح المنسق المشغّل ليشغّل `PHP_BINARY` مباشرة. لا تعد هذه المحاولة اختبارًا فاشلًا للتطبيق.
6. فحص جامع نهائي لهذه الدفعة بعد المراجعة: **53 اختبارًا، 414 تحققًا، كلها ناجحة، صفر فشل/تجاوز**، نحو9.52 ثانية. شمل `QaRelationsInterfaceTest|SchoolBrandingTest|ClassroomSectionsTest|StudentEducationTest|SchoolChatTest|ParentPortalTest|AdminOperationsTest`. JUnit: `tmp/qa-runs/d5b62cbf0b394426bb97bfb4e6ba2e71/phpunit.xml`. تشغيل قبله نجح40/340؛ تشغيل شمل ParentPortal أظهر توقع شعار404 قديم، عُدّل فقط ذلك assertion إلى الشعار المعتمد المؤكد مع إبقاء اختبار manifest المخصص وservice worker كاملين. فحوص `node --check public/js/portal-system.js` و`git diff --check` ناجحة.
7. مراجعة مستقلة من وكيل الصلاحيات أكدت بقاء قيد المشاركين في المرشحات وسلامة توقيعQR وpalette، وكشفت خطأ اسم الدور `finance_officer` الذي صُحح إلى `financial_officer` مع اختبار. أصلحت كتابة alt القديمة في صفحة تحقق البطاقة لتستعمل اسم المدرسة من view. مراجعة متصفح المنسق كشفت تداخل تحكم القائمة الأصلي مع inline الجديد؛ أزيل التحكم المكرر وربط زر الإغلاق بالتحكم المشترك، مع padding أعلى الشعار وعودة التركيز والتمرير.

الأوامر المتاحة للإعادة:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/qa/run-isolated.ps1 tests/Feature/QaRelationsInterfaceTest.php --colors=never
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/qa/run-isolated.ps1 tests/Feature/SchoolBrandingTest.php --colors=never
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/qa/run-isolated.ps1 --filter 'QaRelationsInterfaceTest|SchoolBrandingTest|SchoolAccountNumberingTest|ClassroomSectionsTest|StudentEducationTest|SchoolChatTest' --colors=never
```

## ملفات ومتطلبات النشر

المكونات: `OperationsController` مرشحات/لون، `StudentController` فرز/تجميع البحث، `SchoolChatController::index`، `ExcelImportService::classes`، `EducationController::lesson` فهرس مصرح، `ParentPwaController` manifest، `SchoolBranding` palette، `AppServiceProvider` لون، `AcademicClassroomOrder` ترتيب، `routes/web.php` مسارات الموقع والأيقونات؛ Views المقابلة ومكون `check-list` وCSS admin/student/chat/portal-system وJavaScript portal-system. أُحدثت نسخ URLs للأصول المعدلة لتجنب كاش قديم. لا إعادة بناء كبرى أو إطار جديد.

لا migrations ولا متغيرات بيئة جديدة أو معدلة في فرق هذه الدفعة. يلزم نشر PHP وBlade والأصول العامة CSS معًا، ثم إعادة توليد route/view caches على النسخة المعتمدة بحسب إجراء المنسق. لا تعمل الأيقونات الجديدة مع route cache قديم. لم تنفذ هذه الأوامر على الاستضافة. التراجع يعيد ملفات التطبيق والأصول السابقة، ولا يحذف إعداد اللون المحفوظ أو العلاقات. الشكل القديم يتجاهل اللون؛ لا down ولا حذف بيانات.

## اكتشاف إضافي مستقل

QA-NEW-UI-001: البحث بالاسم في قائمة الطلاب كان OR غير مجمع ويمكنه تجاوز فلتر الصف. أُصلح تحت الكود المشترك مع QA027، وأثبت الاختبار نتيجة واحدة في الصف المحدد بدل نتيجتين. ليس هذا ادعاءً بأن التقرير وصف السبب نفسه. البوابة ما زالت مقيدة بالإدارة.

### QA-NEW-PWA-01: أيقونة احتياطية مفقودة تفشل في الخادم وتمنع تثبيت العامل

- المصدر: اكتشاف أثناء فحص المنسق لسجل الخادم المحلي بعد مرور فحوص واجهة المتصفح؛ سجل fixture الاصطناعية `dcde5e544a615507` أظهر 38 خطأ في `/school-app-icon`. ليس بندًا من التقارير الأصلية ولا يُخفى تحت نجاح QA-026 الجزئي.
- معيار القبول: `/school-app-icon` يعيد PNG معتمدًا صالحًا عند غياب إعداد الشعار أو فقد الملف القديم أو عدم صلاحية نوعه، مع بقاء الاسم والشعار المخصص الصالح. روابط shortcuts وموارد precache كلها متاحة؛ تثبيت وتفعيل العاملين ممكن محليًا دون تخزين صفحات الحساب أو APIs.
- السبب: `ParentPwaController::icon` وshortcuts كانا يرجعان إلى `/icons/parent-icon-192.png` غير الموجود. قوائم precache للعاملين احتوت أربعة ملفات أيقونات مفقودة، فيرفض `cache.addAll` التثبيت. بقيت مراجع مفقودة أيضًا في badge وapple-touch وصفحة الخطأ. لم يكن فحص pageerror أو manifest وحده يكشف فشل طلب الصورة.
- الإصلاح: استجابة افتراضية من `SchoolBranding::iconPng(192)` بالهوية الموجودة، وshortcuts إلى endpoint المعتمد؛ parent SW نسخة v9، وstudent SW نسخة v2 بأيقونات endpoint وبـquery المطابق لـmanifest. يحترم fetch مفاتيح cache الكاملة؛ لم يُوسع التخزين إلى صفحات الحساب. badge يستخدم endpoint المعتمد، وapple-touch مقاس 180، وصورة الخطأ ملف الشعار الأصلي مباشرة؛ الفرع السابق كان يضيف `storage/` خطأ إلى المسار العام رغم ثبات logo في provider. لا صورة جديدة ولا متغير أو migration.
- الملفات: `app/Http/Controllers/ParentPwaController.php`، `public/parent-sw.js`، `public/student-sw.js`، `resources/views/admin/layout.blade.php`، `resources/views/student/layout.blade.php`، `resources/views/errors/school.blade.php`، `tests/Feature/ParentPwaFallbackTest.php`، وتحقق إضافي داخل اختبار `ParentPortalTest` القائم.
- قبل الإصلاح: `ParentPwaFallbackTest` سبعة اختبارات، 19 تحققًا، سبعة فاشلة: ثلاثة 500 للمسار، وثلاثة shortcuts 404، وprecache 404. دليل JUnit: `tmp/qa-runs/d08483a721b44e318922e96f06fec13a/phpunit.xml`.
- بعده: `ParentPwaFallbackTest|ParentPortalTest|SchoolBrandingTest` نجحت أولًا 27/27 و205 تحققات. كشف المراجع فرع صورة الخطأ الفعلي، فأضيف assertion يفشل على `storage/icons/nasbia-logo.jpg`: فشل 1/20 في `tmp/qa-runs/8b5b3901ea344af1bd427a5aaed7a60d/phpunit.xml`. بعد تصحيحه نجحت المجموعة النهائية **27/27 و206 تحققات خلال 6.186 ثانية**؛ الدليل `tmp/qa-runs/7a237324bcab412e96cc4472e04c45ff/phpunit.xml`. يشمل بقاء custom SVG والاسم المخصص، وأبعاد PNG، وno-store، وموارد precache وصورة الخطأ. `node --test tests/js/student-pwa.test.mjs`: 6/6؛ وفحص صياغة العاملين و`git diff --check` ناجحان. البحث في app/public/resources/routes لم يجد أي مرجع `icons/parent-icon*` أو `icons/parent-maskable*` باقٍ.
- الحالة: `FIXED_LOCAL_TESTED`. نجح `browser-pwa.cjs` بنتيجة 2/2: تثبيت وتفعيل parent v9 وstudent v2، وثمانية انتقالات offline استجاب لها Service Worker، وكاش عام فقط دون صفحات حساب، ولا استجابات HTTP فاشلة أثناء التثبيت. الدليل `docs/qa/evidence/browser-pwa.json` وملحق المراجعة المستقلة. لا يثبت ذلك تثبيت PWA على جهاز فعلي أو Push عند إغلاقه؛ QA-026 وQA-022 يبقيان على حدودهما.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/qa/run-isolated.ps1 --filter 'ParentPwaFallbackTest|ParentPortalTest|SchoolBrandingTest' --colors=never
node --test tests/js/student-pwa.test.mjs
```

هذه حالات تحقق محلية؛ لا تمثل اعتماد إطلاق أو إغلاق تحقق الجهاز. أحدث نتيجة نهائية وبصمة الفرق ومصفوفة المتصفح في `TEST_EVIDENCE.md` والسجل العام عند المنسق.
