<?php

namespace App\Http\Controllers;

use App\Http\Requests\StadiumStoreRequest;
use App\Http\Requests\StadiumUpdateRequest;
use App\Http\Resources\StadiumResource;
use App\Services\StadiumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class StadiumController extends Controller
{
    public function __construct(private readonly StadiumService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => StadiumResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function store(StadiumStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->store($data);

            return response()->json(['data' => new StadiumResource($response)], 201);
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

            return response()->json(['data' => new StadiumResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function update(StadiumUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->update($data, $id);

            return response()->json(['data' => new StadiumResource($response)]);
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
