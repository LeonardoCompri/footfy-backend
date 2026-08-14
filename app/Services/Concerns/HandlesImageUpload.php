<?php

namespace App\Services\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait HandlesImageUpload
{
    /**
     * Persiste o arquivo no disco "public" e retorna o caminho relativo gerado.
     */
    protected function storeImage(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, 'public');
    }

    /**
     * Remove a imagem antiga do disco "public", se existir.
     */
    protected function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
