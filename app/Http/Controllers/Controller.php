<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Controller
{
    protected function listResponse(Collection|LengthAwarePaginator $result, string $resource): JsonResponse
    {
        if (! $result instanceof LengthAwarePaginator) {
            return response()->json(['data' => $resource::collection($result)]);
        }

        return response()->json([
            'data' => $resource::collection($result->items()),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }
}
