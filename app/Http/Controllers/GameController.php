<?php

namespace App\Http\Controllers;

use App\Http\Requests\GameIndexRequest;
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

    public function index(GameIndexRequest $request): JsonResponse
    {
        try {
            $leagueId = $request->validated('league_id');
            $filters = array_filter(
                $request->safe()->only(['round', 'phase', 'status', 'team_id']),
                fn ($value) => $value !== null && $value !== ''
            );

            if ($request->has('page') || $request->has('per_page')) {
                $paginator = $this->service->paginate(
                    $leagueId,
                    $filters,
                    (int) ($request->validated('per_page') ?? 20),
                    (int) ($request->validated('page') ?? 1)
                );

                return response()->json([
                    'data' => GameResource::collection($paginator->items()),
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'last_page' => $paginator->lastPage(),
                    ],
                ]);
            }

            $response = $this->service->index($leagueId, $filters);

            return response()->json(['data' => GameResource::collection($response)]);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
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

            return response()->json(['data' => new GameResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(["message" => $ex->getMessage()], 422);
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
