<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeagueStoreRequest;
use App\Http\Requests\LeagueUpdateRequest;
use App\Http\Resources\LeagueResource;
use App\Services\DashboardService;
use App\Services\LeagueService;
use App\Services\StandingsService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeagueController extends Controller
{
    public function __construct(
        private readonly LeagueService $service,
        private readonly StandingsService $standingsService,
        private readonly StatisticsService $statisticsService,
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => LeagueResource::collection($response)]);
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

            return response()->json(['data' => LeagueResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function publicShow(string $id): JsonResponse
    {
        try {
            $response = $this->service->publicShow($id);

            return response()->json(['data' => new LeagueResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    // Tabela da Liga (classificação e/ou chaveamento, conforme o formato da liga).
    public function table(string $id): JsonResponse
    {
        try {
            $league = $this->service->show($id);
            $response = $this->standingsService->build($league);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function publicTable(string $id): JsonResponse
    {
        try {
            $league = $this->service->publicShow($id);
            $response = $this->standingsService->build($league);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    // Estatísticas da liga inteira (artilharia, assistências, cartões, gols por rodada/fase
    // e vitória mandante/empate/vitória visitante) — grupos + mata-mata somados.
    public function statistics(string $id): JsonResponse
    {
        try {
            $league = $this->service->show($id);
            $response = $this->statisticsService->build($league);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function publicStatistics(string $id): JsonResponse
    {
        try {
            $league = $this->service->publicShow($id);
            $response = $this->statisticsService->build($league);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    // Dashboard do admin: contadores do cabeçalho, últimos resultados/próximas partidas e os
    // gráficos de gols por rodada/fase e vitória mandante/empate/vitória visitante.
    public function dashboard(string $id): JsonResponse
    {
        try {
            $league = $this->service->show($id);
            $response = $this->dashboardService->build($league);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function store(LeagueStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->store($data);

            return response()->json(['data' => new LeagueResource($response)], 201);
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

            return response()->json(['data' => new LeagueResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function update(LeagueUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->update($data, $id);

            return response()->json(['data' => new LeagueResource($response)]);
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
