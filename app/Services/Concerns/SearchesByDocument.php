<?php

namespace App\Services\Concerns;

trait SearchesByDocument
{
    protected function documentSearch(?string $search): ?string
    {
        if (blank($search) || ! preg_match('/^[\d.\-\s]+$/', $search)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $search);

        return $digits === '' ? null : $digits;
    }
}
