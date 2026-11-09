<link rel="stylesheet" href="{{ asset('css/school-branding.css') }}?v=20261031-nasbia">
@auth
<style>:root { --school-navy: {{ $schoolBranding['theme_color'] }}; --school-navy-light: color-mix(in srgb, {{ $schoolBranding['theme_color'] }} 82%, #000); }</style>
@endauth
