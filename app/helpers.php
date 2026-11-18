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

if (! function_exists('mask_sensitive_data')) {
    function mask_sensitive_data(array $data): array
    {
        $normalize = static fn (int|string $key): string => str_replace(['-', '_'], '', strtolower((string) $key));
        $sensitiveKeys = array_map($normalize, config('audit.sensitive_keys', []));
        $documentKeys = array_map($normalize, config('audit.document_keys', []));

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalizedKey = $normalize($key);

            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $data[$key] = '***';
            } elseif (in_array($normalizedKey, $documentKeys, true)) {
                $digits = is_scalar($value) ? preg_replace('/\D/', '', (string) $value) : '';
                $data[$key] = strlen($digits) >= 11 ? '***.***.***-'.substr($digits, -2) : '***';
            } elseif (is_array($value)) {
                $data[$key] = mask_sensitive_data($value);
            }
        }

        return $data;
    }
}
