<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoalStoreRequest;
use App\Http\Requests\GoalUpdateRequest;
use App\Http\Resources\GoalResource;
use App\Services\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class GoalController extends Controller
{
    public function __construct(private readonly GoalService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => GoalResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function store(GoalStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->store($data);

            return response()->json(['data' => new GoalResource($response)], 201);
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

            return response()->json(['data' => new GoalResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                "message" => "Houve um erro no servidor, tente novamente!"
            ], 500);
        }
    }

    public function update(GoalUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->update($data, $id);

            return response()->json(['data' => new GoalResource($response)]);
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
