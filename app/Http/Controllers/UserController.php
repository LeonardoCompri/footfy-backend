<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserLeaguesRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => UserResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        try {
            $response = $this->service->store($request->validated());

            return response()->json(['data' => new UserResource($response)], 201);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $response = $this->service->show($id);

            return response()->json(['data' => new UserResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function update(UserUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $response = $this->service->update($request->validated(), $id);

            return response()->json(['data' => new UserResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
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
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function syncLeagues(UserLeaguesRequest $request, string $id): JsonResponse
    {
        try {
            $leagueIds = $request->validated()['league_ids'];
            $response = $this->service->syncLeagues($id, $leagueIds);

            return response()->json(['data' => new UserResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function removeLeague(string $id, string $lid): JsonResponse
    {
        try {
            $response = $this->service->removeLeague($id, $lid);

            return response()->json(['data' => new UserResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }
}
