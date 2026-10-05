@php
    $iconSubject = mb_strtolower($subjectName ?? '');
    $iconKind = 'book';
    foreach ([
        'math' => ['رياضيات', 'حساب', 'جبر', 'هندسة'],
        'statistics' => ['إحصاء', 'احصاء'],
        'arabic' => ['عربية', 'عربي', 'لغة عربية', 'نحو', 'قراءة', 'إملاء', 'بلاغة', 'خط'],
        'english' => ['إنجليزي', 'انجليزي', 'english'],
        'french' => ['فرنسي', 'french'],
        'chemistry' => ['كيمياء'],
        'physics' => ['فيزياء', 'ميكانيكا'],
        'biology' => ['أحياء', 'احياء', 'حياة'],
        'science' => ['علوم'],
        'geography' => ['جغرافيا'],
        'geology' => ['جيولوجيا', 'أرض'],
        'history' => ['تاريخ'],
        'religion' => ['إسلام', 'اسلام', 'قرآن', 'قران', 'دين'],
        'computer' => ['حاسوب', 'حوسبة', 'معلومات', 'تقنية', 'برمجة'],
        'art' => ['فنية', 'رسم', 'فنون'],
        'music' => ['موسيق'],
        'sport' => ['بدنية', 'رياضة'],
        'civic' => ['وطنية', 'مواطنة', 'اجتماعيات', 'مدنية'],
        'economy' => ['اقتصاد', 'محاسبة', 'تجارة'],
        'philosophy' => ['فلسفة', 'منطق'],
        'psychology' => ['نفس', 'اجتماع'],
        'health' => ['صحية', 'صحة'],
        'agriculture' => ['زراعة', 'زراعي', 'بيئة'],
        'technology' => ['مهني', 'صناعي', 'تكنولوجيا'],
    ] as $candidate => $words) {
        foreach ($words as $word) {
            if (str_contains($iconSubject, $word)) {
                $iconKind = $candidate;
                break 2;
            }
        }
    }
@endphp
<span class="lib-subject-icon lib-subject-icon--{{ $iconKind }}" aria-hidden="true">
    @if($iconKind === 'arabic')
        <span class="lib-letter">ض</span>
    @elseif(in_array($iconKind, ['english', 'french']))
        <span class="lib-letter lib-letter--latin">{{ $iconKind === 'english' ? 'Aa' : 'É' }}</span>
    @else
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" focusable="false">
            @switch($iconKind)
                @case('math')<rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 7h8M8 12h3m-1.5-1.5v3M15 12h2M8 17h3m4-1h2m-2 2h2"/>@break
                @case('statistics')<path d="M4 3v17h17M8 16v-5m5 5V6m5 10V9"/>@break
                @case('chemistry')<path d="M9 3h6m-5 0v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3M8 14h8m-6 3h.01m4 1h.01"/>@break
                @case('physics')<ellipse cx="12" cy="12" rx="10" ry="4"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(120 12 12)"/><circle cx="12" cy="12" r="1" fill="currentColor"/>@break
                @case('biology')<path d="M7 3c0 9 10 9 10 18M17 3c0 9-10 9-10 18M8 5h8M10 9h4m-4 6h4m-6 4h8"/>@break
                @case('science')<path d="m10 3 5 2-4 10-5-2 4-10Zm1 12 1 2M5 21h15M8 17a6 6 0 1 0 10-7M5 16l6 2"/>@break
                @case('geography')<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 6h14M5 18h14"/>@break
                @case('geology')<path d="m2 20 7-15 4 8 3-5 6 12H2Zm4-8 3 2 3-3m2 5 2-2 3 2"/>@break
                @case('history')<path d="M4 3h16M4 21h16M6 3v4c0 3 6 4 6 5s-6 2-6 5v4m12-18v4c0 3-6 4-6 5s6 2 6 5v4M7 18h10"/>@break
                @case('religion')<path d="M4 20h16M6 20v-8h12v8M9 12V9c0-2 3-4 3-4s3 2 3 4v3m-5 8v-4a2 2 0 0 1 4 0v4M3 6v14m18-14v14M12 2v2"/>@break
                @case('computer')<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4m-4-12-2 2 2 2m8-4 2 2-2 2"/>@break
                @case('art')<path d="M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-4 2 2 0 0 1 1-4h3c2 0 3-1 3-3 0-4-4-7-9-7Z"/><circle cx="7" cy="10" r=".7"/><circle cx="11" cy="7" r=".7"/><circle cx="16" cy="8" r=".7"/>@break
                @case('music')<path d="M10 17V5l10-2v12M10 8l10-2"/><ellipse cx="7" cy="18" rx="3" ry="2"/><ellipse cx="17" cy="16" rx="3" ry="2"/>@break
                @case('sport')<circle cx="15" cy="4" r="2"/><path d="m10 9 4-2 3 5 4 1M3 10l5-1 2 5-4 6m4-6 5 1 1 6m-5-10 3-4"/>@break
                @case('civic')<path d="M3 10 12 3l9 7M4 10h16M5 20h14M7 10v10m5-10v10m5-10v10M3 22h18"/>@break
                @case('economy')<path d="M3 20h18M6 16v-4m6 4V8m6 8V4M4 8l6-4 4 1 6-3"/>@break
                @case('philosophy')<path d="M9 18h6m-5 3h4M9 15a7 7 0 1 1 6 0v3H9v-3Zm3-9v5m0 3h.01"/>@break
                @case('psychology')<path d="M9 21v-5a7 7 0 1 1 10-6l2 3h-3v4h-4v4M9 7a2 2 0 0 1 4 0 2 2 0 1 1 0 4H9a2 2 0 1 1 0-4Z"/>@break
                @case('health')<path d="M12 21 4 13a5 5 0 0 1 8-7 5 5 0 0 1 8 7l-8 8ZM9 12h6m-3-3v6"/>@break
                @case('agriculture')<path d="M5 19C1 9 9 3 21 3c0 12-6 20-16 16Zm0 0L16 8M9 15v-5m0 5h5"/>@break
                @case('technology')<path d="m14 6 4-3a6 6 0 0 1-7 8L4 21l-3-3 10-7a6 6 0 0 1 8-7l-5 2Zm0 0 3 3"/>@break
                @default<path d="M12 5v16M3 4h5a4 4 0 0 1 4 2 4 4 0 0 1 4-2h5v15h-5a4 4 0 0 0-4 2 4 4 0 0 0-4-2H3V4Z"/>
            @endswitch
        </svg>
    @endif
</span>
