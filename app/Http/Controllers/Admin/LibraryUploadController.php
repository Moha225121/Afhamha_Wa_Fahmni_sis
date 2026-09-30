<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LibraryUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LibraryUploadController extends Controller
{
    public function start(Request $request, LibraryUploadService $uploads): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'size' => 'required|integer|min:1']);
        if (! in_array(strtolower(pathinfo($data['name'], PATHINFO_EXTENSION)), config('library.extensions'), true)) {
            throw ValidationException::withMessages(['file' => 'نوع الملف غير مدعوم.']);
        }

        return response()->json($uploads->start($request->user()->id, $data['name'], (int) $data['size']), 201);
    }

    public function chunk(Request $request, string $upload, LibraryUploadService $uploads): JsonResponse
    {
        $request->validate(['index' => 'required|integer|min:0', 'chunk' => 'required|file|max:'.(int) ceil(config('library.chunk_bytes') / 1024)]);

        return response()->json($uploads->chunk($upload, $request->user()->id, $request->integer('index'), $request->file('chunk')));
    }

    public function cancel(Request $request, string $upload, LibraryUploadService $uploads): JsonResponse
    {
        $uploads->discard($upload, $request->user()->id);

        return response()->json(['cancelled' => true]);
    }
}
