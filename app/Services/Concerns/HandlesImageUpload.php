<?php

namespace App\Services\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait HandlesImageUpload
{
    protected function storeImage(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, 'public');
    }

    protected function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
