<?php

namespace App\Http\Controllers;

use App\Models\LibraryResource;
use App\Services\LibraryCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class LibraryCatalogController extends Controller
{
    public function index(Request $request, LibraryCatalogService $catalog): View
    {
        $data = $catalog->data($request, $request->user());

        return view($request->user()->role.'.library.index', $data);
    }

    public function read(Request $request, int $resource, LibraryCatalogService $catalog): View
    {
        $resource = $this->resource($request, $resource, $catalog);
        $this->localPath($resource);

        return view('library.reader', [
            'resource' => $resource,
            'layout' => $request->user()->role.'.layout',
            'routePrefix' => $request->user()->role.'.library',
            'bookTypes' => config('library.book_types', LibraryCatalogService::BOOK_TYPES),
            'grades' => config('library.grades', LibraryCatalogService::GRADES),
        ]);
    }

    public function file(Request $request, int $resource, LibraryCatalogService $catalog): BinaryFileResponse
    {
        return $this->respond($this->resource($request, $resource, $catalog), false);
    }

    public function download(Request $request, int $resource, LibraryCatalogService $catalog): BinaryFileResponse
    {
        return $this->respond($this->resource($request, $resource, $catalog), true);
    }

    private function resource(Request $request, int $id, LibraryCatalogService $catalog): LibraryResource
    {
        return $catalog->visibleQuery($request->user())->with(['subject', 'classroom'])->findOrFail($id);
    }

    private function localPath(LibraryResource $resource): string
    {
        $disk = $resource->disk ?: 'public';
        $path = str_replace('\\', '/', $resource->file_path);
        abort_unless(in_array($disk, ['local', 'public'], true), 404);
        abort_if($path === '' || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, "\0") || preg_match('~(^|/)\.{1,2}(/|$)~', $path), 404);
        $storage = Storage::disk($disk);
        $root = realpath($storage->path(''));
        $file = realpath($storage->path($path));
        abort_unless($root !== false && $file !== false && is_file($file) && is_readable($file), 404);
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $resolved = str_replace('\\', '/', $file);
        if (DIRECTORY_SEPARATOR === '\\') {
            $prefix = strtolower($prefix);
            $resolved = strtolower($resolved);
        }
        abort_unless(str_starts_with($resolved, $prefix), 404);

        return $file;
    }

    private function respond(LibraryResource $resource, bool $download): BinaryFileResponse
    {
        $path = $this->localPath($resource);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isPdf = $extension === 'pdf';
        $name = trim($resource->title) ?: ($resource->original_name ?: 'resource');
        $name = trim(preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? '', ' ._');
        $name = rtrim(mb_strcut($name, 0, 180, 'UTF-8'), ' ._');
        if ($name === '') {
            $name = 'resource';
        }
        if ($extension !== '' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
            $name .= '.'.$extension;
        }
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $isPdf ? 'application/pdf' : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ], false);
        $response->setContentDisposition(
            $download || ! $isPdf ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $name,
            'resource-'.$resource->id.($extension !== '' ? '.'.$extension : ''),
        );

        return $response;
    }
}
