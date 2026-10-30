<meta name="theme-color" content="{{ $schoolBranding['theme_color'] }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="النسبية">
<link rel="manifest" href="{{ $manifestUrl ?? asset('manifest.webmanifest').'?v=20261029-nasbia' }}">
<link rel="icon" href="{{ \App\Support\SchoolBranding::logoUrl() }}" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('school-branding/icon-180.png') }}?v=20261029-nasbia" sizes="180x180">
