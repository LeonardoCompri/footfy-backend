<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChampionshipStoreRequest;
use App\Http\Requests\ChampionshipUpdateRequest;
use App\Http\Resources\ChampionshipResource;
use App\Services\ChampionshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChampionshipController extends Controller
{
    public function __construct(private readonly ChampionshipService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => ChampionshipResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function publicIndex(): JsonResponse
    {
        try {
            $response = $this->service->publicIndex();

            return response()->json(['data' => ChampionshipResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function publicShow(string $id): JsonResponse
    {
        try {
            $response = $this->service->publicShow($id);

            return response()->json(['data' => new ChampionshipResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function store(ChampionshipStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->store($data);

            return response()->json(['data' => new ChampionshipResource($response)], 201);
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

            return response()->json(['data' => new ChampionshipResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function update(ChampionshipUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->update($data, $id);

            return response()->json(['data' => new ChampionshipResource($response)]);
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
