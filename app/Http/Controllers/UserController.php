<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserIndexRequest;
use App\Http\Requests\UserLeaguesRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    public function index(UserIndexRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $leagueId = Arr::pull($filters, 'league_id');

            $response = $this->service->index($leagueId, $filters, $request->wantsPagination());

            return $this->listResponse($response, UserResource::class);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        try {
            $response = $this->service->store($request->validated());

            return response()->json(['data' => new UserResource($response)], 201);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            log_error($th);

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
            log_error($th);

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
            log_error($th);

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
            log_error($th);

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
            log_error($th);

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
            log_error($th);

            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }
}
