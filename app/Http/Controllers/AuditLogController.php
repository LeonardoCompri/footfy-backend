<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Throwable;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $service) {}

    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        try {
            $response = $this->service->index($request->validated());

            return $this->listResponse($response, AuditLogResource::class);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }
}
