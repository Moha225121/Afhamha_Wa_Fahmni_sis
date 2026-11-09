# أدلة قبول المصدر الكامل — 2026-11-09

هذه نتائج جديدة من **نسخة ZIP مفكوكة ونظيفة** لمدرسة النسبية. الأساس `1a5cfe27c72e38acd05c837682a3f29b0d7b3402`، والفرع `codex/qa-release-readiness-20261108`، مع التغييرات غير الملتزم بها. لا commit أو push أو نشر أو اتصال إنتاج.

معرف المصدر المختبر: **`bd85ab15c580a7b239acf9996ed1e1a8c9ac589b47203771f420825e0b1b1f84`**. يحسب SHA256 لسجلات مرتبة من المسار النسبي ثم NUL ثم بصمة الملف ثم LF، لجميع ملفات المصدر المشحونة عدا `docs/**` لتجنب المرجع الذاتي. تشمل قائمة `RELEASE_MANIFEST.json` الوثائق والأدلة أيضًا؛ HEAD وحده لا يعرّف العمل غير الملتزم به.

فُكت الحزمة المرشحة ذات المعرف نفسه في `tmp/qa-closeout/clean/bd85ab15c580/source` بعد مطابقة939 ملفًا والبصمات. ثُبتت الاعتمادات داخله من ملفات القفل، دون نسخ vendor أو node_modules من التطوير. استُكملت الوثائق والأدلة بعد الاختبارات؛ لم تتغير ملفات الكود أو القفل أو الأصول المختبرة. يفحص التغليف النهائي تطابق معرف المصدر وإعادة فك ZIP وبصمات كل ملف.

## البيئة والعزل

Windows x86_64؛ PHP8.4.21، Composer2.9.5، Laravel13.30.0، PHPUnit12.5.33، Node26.1.0، Vite8.3.5، PostgreSQL17.11. Edge156.0.4314.58 عبر Playwright فعلي headless مع عروض ولمس محاكيين، وليس هاتفًا فعليًا. Docker/Podman غير متاحين وWSL غير مثبت؛ **لم يختبر Linux**. فحص حالة أحرف المسارات ليس بديلًا عن قبول Linux.

تمنع tests/TestCase.php ومشغلات QA تحميل `.env` الحقيقي والكاش غير المعزول، وتتحقق من الاتصال قبل فتحه، وتعطل اعتمادات الإرسال، وتثبت mail=array واتصال الطابور بقاعدة الاختبار نفسها وتمنع HTTP الخارجي غير المحاكى. SQLite في الذاكرة، والملفات والجلسات والسجلات في مجلد جديد لكل تشغيل. اختبارات العزل نفسها ضمن المجموعة.

عنقود النسخة المفكوكة جديد من ثنائيات PostgreSQL محمولة موجودة سابقًا، على `127.0.0.1:55439` فقط، بدور qa_release_owner وبيانات اصطناعية. يطابق المشغل marker وPID وdata_directory والقاعدة والدور. قواعد منفصلة: afhamha_testing للاختبارات، nasbia_qa_upgrade للترقية، nasbia_qa_restore للاستعادة، nasbia_qa_browser للمتصفح. لا تثبيت محرك أو تغيير خدمة عامة. marker تحت `source/tmp/qa-postgres/170900c5f4734847bcb279c9815d1315` في النسخة الاختبارية فقط، ولا يدخل الحزمة.

## النتائج النهائية للنسخة المفكوكة

الدليل المجمع هو [clean-source-verification.json](evidence/clean-source-verification.json)، ويضم البصمات وملخص JUnit وأوامر التنفيذ ورموز الخروج والنوافذ الزمنية. الأعداد الجزئية في وثائق الدفعات متداخلة؛ لا تُجمع مع هذه المجموعة.

| الفحص | النتيجة الفعلية |
|---|---|
| Composer من القفل |117 حزمة، exit0؛ no-scripts/no-plugins؛ platform requirements ناجحة |
| npm من القفل |150 حزمة،47 ثانية، exit0؛ ignore-scripts؛ لا تحديث قفل |
| PHP / SQLite |**513/513 ناجحة،4175 تأكيدًا، صفر فشل/خطأ/تجاوز**؛ مدة المشغل91.886 ثانية |
| PHP / PostgreSQL17.11 |**513 إجمالًا:512 ناجحة، تجاوز واحد،4172 تأكيدًا، صفر فشل/خطأ**؛156.507 ثانية |
| JavaScript |**22/22**، صفر فشل/تجاوز،820.537ms، exit0 |
| Vite |نجاح في **16.45 ثانية**، exit0؛ قيم VITE_FIREBASE فارغة صراحة داخل عملية Node |
| ترقية واستعادة PostgreSQL من مصدر بلا Git |**32/32**: خمسة للأساس،23 للترقية، أربعة للاستعادة؛ exit0 |
| تنافس FCM وإعادة تشغيل العامل |**10/10** على PostgreSQL وعمليات PHP فعلية مع مزود محاكى؛ exit0 |
| runtime |serialization وroute:cache وview:cache وschedule:list ناجحة؛ **319 مسارًا**؛ exit0 |
| الصياغة |**328 ملف PHP،41 ملف JS،8 ملفات PowerShell** ناجحة؛ Blade عبر view:cache |
| أداة التغليف |**19/19**: ZIP صالح وتلاعب بالبصمة/source_id وتغطية ناقصة ومسارات/ADS/روابط/تصادم أسماء مرفوضة |

تجاوز PostgreSQL الوحيد هو `test_sqlite_migration_refuses_transaction_before_altering_existing_schema`؛ شرطه خاص بمنع إعادة بناء جدول SQLite داخل معاملة خارجية، وقد نجح في مجموعة SQLite نفسها. ليس اختبار تطبيق معطلًا لإخفاء فشل PostgreSQL. تحذير PDF.js عن canvas الاختياري لم يمنع تحميل الوحدات وتحليل الصفحات في JavaScript.

ارتفع العدد من492 تاريخيًا إلى513 بسبب **21 اختبارًا جديدًا**: ClassroomDuplicateValidationTest خمسة، CloseoutDisplayTimezoneTest خمسة، CloseoutFcmReliabilityTest تسعة، CloseoutWebPushPrivacyTest اثنان. لا اختبار قديم محذوف. صُححت افتراضات معرفات ثابتة في SchoolChatTest وStudentFinanceTest، وعُزل مخطط اختبار DDL عن بقية اختبارات PostgreSQL؛ الفشل الأول وأسبابه في [تقرير PostgreSQL](POSTGRES_VALIDATION_AR.md).

## المتصفح والرحلات

شُغلت السكربتات السبعة بالتتابع من المصدر المفكوك وبنائه نفسه، على fixture جديدة `fd26797295aa3ce1` وPostgreSQL17.11، عبر `127.0.0.1:8765`. الحسابات الخمسة اصطناعية، ومنها ولي لطفلين بصفين مختلفين، وولي لطفل واحد، وطالب غير مرتبط. الطلبات الخارجية محجوبة.

| السكربت | الفحوص | ما يثبته |
|---|---:|---|
| browser-smoke |15| العلاقات والحفظ/reload والصلاحيات والمظهر وRTL والمرشحات والأيقونات والرجوع |
| browser-learning |6| واجب بمرفق ورفع/تنزيل ومطابقة البايتات وتصحيح ونشر وقراءة الطالب ووليه؛ محادثة وإعلان وملاحظة مشرف |
| browser-academics |9| درس ومرفق ومنع الغريب؛ اختبار موضوعي/يدوي ومنع البدء المبكر ثم الأداء والتصحيح والمراجعة والاعتماد والنشر وقراءة85% |
| browser-tutor |4| إنشاء محادثة خاصة بالمعلم وإعادة فتحها ومنع حساب آخر والطالب/الولي ورسالة إعداد آمنة؛ لا Gemini حي |
| browser-pwa |2| تفعيل عاملي الولي والطالب وثمانية انتقالات offline وكاش عام فقط |
| browser-qa009-closeout |7| إنشاء وتعديل الصف بمادة واحدة وعدة مواد وإعادة قراءة DB، وعرضا390/1440، وثماني حالات خاطئة لكل عرض تعيد422 بلا أثر جزئي |
| browser-pwa-upgrade |2| تحديث عامل الولي v8→v9 والطالب v1→v2، بقاء نموذج غير محفوظ، لا reload قسري، خروج ورجوع وتبديل حساب ومنع بيانات الحساب السابق |

هذه **45 نتيجة تحقق** متفاوتة الاتساع، وليست45 جهازًا أو45 رحلة كاملة. الحسابات الأساسية من seeder اصطناعي؛ لا ادعاء إنشائها كلها من الواجهة. الحضور والاستدعاءات والقيود المالية وحفظ المديونية ومنع الخادم والتكرار وحدود مواعيد الاختبارات مغطاة أيضًا باختبارات HTTP/التكامل. الطباعة وQR وPDF في HTTP/PDF دون طابعة. العروض المختبرة360/390/768/1440، مع اللمس المحاكى ولوحة المفاتيح.

تتضمن `evidence/clean-browser-*.json` نتائج هذه الدورة. بعض السكربتات الأقدم تطبع وصفًا ثابتًا باسم SQLite؛ احتُفظ به تحت original_runner_environment_label وصحح environment وفق descriptor وهوية قاعدة هذه الدورة، **دون تعديل النتائج**. يؤكد ملخص التشغيل أن القاعدة الفعلية PostgreSQL. الأدلة القديمة بأسمائها الأصلية محفوظة.

عامل PWA القديم من HEAD، لكن بعض أيقوناته التاريخية غير موجودة؛ يقدم router الاختبار وحده PNG اصطناعيًا معتمدًا لتفعيل baseline. لا يمثل ذلك تطابق حزمة مثبتة قديمة على هاتف. تفاصيل القيد ومصفوفة الاختبار الخارجي في [قبول الأجهزة وStaging](DEVICE_STAGING_ACCEPTANCE_AR.md).

QA-009 التاريخي يبقى **NOT_REPRODUCED**، ومعيار القبول الحالي **PASS محليًا على PostgreSQL**. رفض التكرار والمعرف غير الرقمي إصلاحان جديدان مستقلان، ولا ينسب إليهما سبب500 القديم. QA-022 وQA-026 يبقيان NEEDS_DEVICE_VERIFICATION.

## الترقية والموثوقية وحدودهما

تحقق BASELINE_MIGRATIONS.json من بصمات42 migration قديمة وملفي إعداد من HEAD، ثم بُني المخطط السابق ببيانات اصطناعية. أخذت pg_dump قبل الترقية؛ طُبقت الترحيلتان فقط، وحُفظت الملكيات والرسائل وحقول السجلات القديمة. اختُبرت FK/الفرادة وNULL والمالك غير النشط/الناقص/الملتبس ومنع نسبة السجلات لمالك مخمن. أحداث FCM القديمة بلا payload لم تُرسل. نجح down الآمن قبل محادثات المعلم، ورُفض بعدها بلا فقد؛ حقن فشل DDL أثبت تراجع معاملة PostgreSQL. ثم pg_dump وpg_restore إلى قاعدة منفصلة ومطابقة **13 جدول fixture** والـsequence. زمن phases على البيانات الصغيرة6.184 و1.117 و0.090 ثانية؛ ليس وعدًا بزمن النسخ أو قفل الإنتاج. انظر [clean-postgres-upgrade.json](evidence/clean-postgres-upgrade.json).

اختبارات worker بمزود محاكى تغطي النجاح الجزئي وعدم إعادة الجهاز ذي الإيصال وأربع محاولات وفواصل10/60/180 وfailed_jobs وإعادة UUID واحد وانتهاء/دوران الرمز وعزل المستلمين. ثبتت ذرية notification/outbox داخل record، وjob بعد commit، واسترداد فشل الإدخال. نجح تنافس عمليتين والعامل الذي ينتهي بعد قبول مزيف؛ تحريك timestamps في fixture يسرع انتظار القفل والحجز ولا يدعي انقطاع طاقة أو انتظارًا فعليًا. ضياع الرد بعد قبول المزود **قد يؤدي لقبولين**؛ لا exactly-once. انظر [clean-fcm-postgres-concurrency.json](evidence/clean-fcm-postgres-concurrency.json) و[تفاصيل الموثوقية](CLOSEOUT_RELIABILITY_AR.md).

خصوصية Push تختبر هوية المستلم الخادمية والجلسة قبل العرض والنقر وتنظيف إشعارات المدرسة عند الخروج/التبديل. غياب الاتصال أو الجلسة يمنع عرض الإشعار الخاص؛ يحتاج ذلك قبول الجهاز، وليس إثبات وصول offline. التوقيت يغطي الغياب والفراغ والقيمة غير الصالحة والكاش وعبور منتصف الليل، دون تغيير التخزين/الجدولة UTC. مساعد المعلم محدود بمالك نشط وسياق خالٍ من قوائم الطلاب، دون تغيير رحلة الطالب أو مزود جديد.

## أوامر QA المنفذة

المرجع التنفيذي الوحيد للاستضافة هو [DIGITALOCEAN_HANDOFF_AR.md](../deployment/DIGITALOCEAN_HANDOFF_AR.md). التالي سجل QA داخل المصدر المفكوك، وليس أمر تشغيل على إنتاج:

```powershell
composer install --no-interaction --prefer-dist --no-scripts --no-plugins --optimize-autoloader
npm ci --ignore-scripts --no-audit --fund=false
composer --no-plugins check-platform-reqs
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/qa/run-isolated.ps1
node --test tests/js/*.test.mjs
php -d memory_limit=512M scripts/qa/runtime-check.php
```

استُدعي run-postgres.ps1 وvalidate-postgres-upgrade.ps1 وrun-fcm-concurrency.ps1 مع marker العنقود الجديد؛ الأدوات تحققت من وجهته قبل التنفيذ. خطوات fixture/router والمتصفح ومتغيرات QA في [قائمة إعادة الاختبار](RETEST_CHECKLIST_AR.md) و[تقرير PostgreSQL](POSTGRES_VALIDATION_AR.md). يحتاج Playwright وEdge مثبتين؛ لا تُرفق ثنائياتهما أو PostgreSQL.

البناء المعزول المنفذ:

```powershell
node --input-type=module -e "for (const key of ['VITE_FIREBASE_API_KEY','VITE_FIREBASE_PROJECT_ID','VITE_FIREBASE_MESSAGING_SENDER_ID','VITE_FIREBASE_APP_ID','VITE_FIREBASE_VAPID_KEY']) process.env[key]=''; const { build } = await import('vite'); await build();"
```

هذا البناء للتحقق فقط؛ public/build مستبعد. يبني المستلم من القفل على نظامه بقيم مشروعه العامة المعتمدة. لا تُنسخ وحدات Windows إلى Linux.

## التاريخ وما لم ينفذ

دفعة2026-11-08 سجلت492/4004 وJS15 و36 فحص متصفح؛ دليلها [final-verification.json](evidence/final-verification.json) وبصمة manifest الفرق `6b86510fbbb03ed2edc9783670175e6d12eb52a24840f7d90bedc9fac5000cb1`. حزمة الفرق السابقة محفوظة. طابقت الجلسة الحالية126/126 ملفًا عند البدء؛ حالة الفرع والملفات المرجعية الغائبة في [CLOSEOUT_SESSION_AR.md](CLOSEOUT_SESSION_AR.md).

المحاولات الحمراء محفوظة في [تقرير PostgreSQL](POSTGRES_VALIDATION_AR.md) و[الموثوقية](CLOSEOUT_RELIABILITY_AR.md) و[قبول الأجهزة](DEVICE_STAGING_ACCEPTANCE_AR.md) و[المراجعة المستقلة](batches/INDEPENDENT_REVIEW.md). لم تُحذف السجلات القديمة؛ اختبارات الفشل المحقون تنتج أخطاء/تحذيرات متوقعة ولا تُخلط مع نافذة المتصفح الجديدة. ملخص النافذة ومستويات ERROR/CRITICAL/EMERGENCY في الدليل المجمع.

لا DigitalOcean أو staging خارجي أو Linux، ولا قياس أقفال بحجم المدرسة أو استعادة بياناتها. لا Gemini/FCM/VAPID حي، ولا هاتف/قفل شاشة/تثبيت قديم حقيقي أو طابعة. المالك والخطوات والدليل لكل فجوة في مصفوفة الأجهزة وقرار الجاهزية. النتائج المحلية لا تمنح VERIFIED_IN_TARGET_ENV ولا موافقة إنتاج.
