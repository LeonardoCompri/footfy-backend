<?php

namespace App\Http\Controllers;

use App\Http\Requests\GameStoreRequest;
use App\Http\Requests\GameUpdateRequest;
use App\Http\Resources\GameResource;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class GameController extends Controller
{
    public function __construct(private readonly GameService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => GameResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function store(GameStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->store($data);

            return response()->json(['data' => new GameResource($response)], 201);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $response = $this->service->show($id);

            return response()->json(['data' => new GameResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function update(GameUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->update($data, $id);

            return response()->json(['data' => new GameResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->destroy($id);

            return response()->json([], 204);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }
}
