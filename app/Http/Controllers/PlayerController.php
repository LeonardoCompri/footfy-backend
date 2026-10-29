<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerStoreRequest;
use App\Http\Requests\PlayerUpdateRequest;
use App\Http\Resources\PlayerResource;
use App\Services\PlayerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlayerController extends Controller
{
    public function __construct(private readonly PlayerService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => PlayerResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function positions(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->positions()]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function publicShow(string $id): JsonResponse
    {
        try {
            $response = $this->service->show($id);

            return response()->json(['data' => new PlayerResource($response)]);
        } catch (ValidationException $ex) {
            // Rota pública: jogador inexistente ou removido (soft delete) responde 404.
            return response()->json(['message' => $ex->getMessage()], 404);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function store(PlayerStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->store($data);

            return response()->json(['data' => new PlayerResource($response)], 201);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $response = $this->service->show($id);

            return response()->json(['data' => new PlayerResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function update(PlayerUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->update($data, $id);

            return response()->json(['data' => new PlayerResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
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
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }
}
