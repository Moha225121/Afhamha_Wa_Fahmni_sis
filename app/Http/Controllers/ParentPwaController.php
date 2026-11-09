<?php

namespace App\Http\Controllers;

use App\Support\SchoolBranding;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ParentPwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $settings = Schema::hasTable('settings') ? DB::table('settings')->pluck('value', 'key') : collect();
        $schoolName = $settings['school_name'] ?? 'مدرسة النسبية';
        $logo = $settings['school_logo'] ?? null;
        $iconUrl = route('school.pwa.icon', absolute: false);
        $mimeType = $this->logoMimeType($logo);

        if ($logo && $mimeType) {
            $iconUrl .= '?v='.substr(hash('sha256', $logo), 0, 12);
        } else {
            $iconUrl = route('school.branding.icon', ['size' => 192], absolute: false);
            $mimeType = 'image/png';
        }

        $icons = ! $logo || ! $this->logoMimeType($logo)
            ? array_map(fn ($size) => ['src' => route('school.branding.icon', ['size' => $size], absolute: false), 'sizes' => $size.'x'.$size, 'type' => 'image/png', 'purpose' => 'any'], [192, 512])
            : ($mimeType === 'image/svg+xml'
            ? [['src' => $iconUrl, 'sizes' => 'any', 'type' => $mimeType, 'purpose' => 'any']]
            : [
                ['src' => $iconUrl, 'sizes' => '192x192', 'type' => $mimeType, 'purpose' => 'any'],
                ['src' => $iconUrl, 'sizes' => '512x512', 'type' => $mimeType, 'purpose' => 'any'],
            ]);

        return response()->json([
            'name' => $schoolName.' - بوابة ولي الأمر',
            'short_name' => $schoolName,
            'description' => 'بوابة ولي الأمر في '.$schoolName,
            'id' => '/parent/',
            'start_url' => '/parent/dashboard',
            'scope' => '/parent/',
            'display' => 'standalone',
            'orientation' => 'any',
            'dir' => 'rtl',
            'lang' => 'ar',
            'background_color' => '#f7f8f5',
            'theme_color' => $settings['theme_color'] ?? '#0e7c86',
            'categories' => ['education', 'productivity'],
            'icons' => $icons,
            'shortcuts' => [
                ['name' => 'الرئيسية', 'short_name' => 'الرئيسية', 'url' => '/parent/dashboard', 'icons' => [['src' => $iconUrl]]],
                ['name' => 'الرسائل', 'short_name' => 'الرسائل', 'url' => '/parent/messages', 'icons' => [['src' => $iconUrl]]],
                ['name' => 'الإشعارات', 'short_name' => 'الإشعارات', 'url' => '/parent/notifications', 'icons' => [['src' => $iconUrl]]],
            ],
        ])->header('Content-Type', 'application/manifest+json; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function icon(): Response
    {
        $logo = Schema::hasTable('settings') ? DB::table('settings')->where('key', 'school_logo')->value('value') : null;
        $mimeType = $this->logoMimeType($logo);

        if (! $logo || ! $mimeType) {
            return response(SchoolBranding::iconPng(192), 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-store',
            ]);
        }

        return response(Storage::disk('public')->get($logo), 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function logoMimeType(?string $logo): ?string
    {
        if (! $logo || ! Storage::disk('public')->exists($logo)) {
            return null;
        }

        $mimeType = Storage::disk('public')->mimeType($logo);

        return in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], true)
            ? $mimeType
            : null;
    }
}
