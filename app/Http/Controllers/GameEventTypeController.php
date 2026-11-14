<?php

namespace App\Http\Controllers;

use App\Http\Requests\GameEventTypeStoreRequest;
use App\Http\Requests\GameEventTypeUpdateRequest;
use App\Http\Resources\GameEventTypeResource;
use App\Services\GameEventTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class GameEventTypeController extends Controller
{
    public function __construct(private readonly GameEventTypeService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => GameEventTypeResource::collection($response)]);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function store(GameEventTypeStoreRequest $request): JsonResponse
    {
        try {
            $response = $this->service->store($request->validated());

            return response()->json(['data' => new GameEventTypeResource($response)], 201);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function update(GameEventTypeUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $response = $this->service->update($request->validated(), $id);

            return response()->json(['data' => new GameEventTypeResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->destroy($id);

            return response()->json([], 204);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }
}
