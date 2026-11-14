<?php

use Illuminate\Support\Facades\Log;

if (! function_exists('log_error')) {
    function log_error(Throwable $th, ?string $message = null, array $context = []): void
    {
        try {
            $userId = auth()->id();
        } catch (Throwable) {
            $userId = null;
        }

        Log::error($message ?? $th->getMessage(), [
            'exception' => $th,
            'action' => request()->route()?->getActionName(),
            'user_id' => $userId,
        ] + $context);
    }
}
