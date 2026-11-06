<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeagueDestroyRequest;
use App\Http\Requests\LeagueDrawRoundRequest;
use App\Http\Requests\LeagueGroupsRequest;
use App\Http\Requests\LeagueStoreRequest;
use App\Http\Requests\LeagueUpdateRequest;
use App\Http\Resources\LeagueResource;
use App\Services\DashboardService;
use App\Services\LeagueDrawService;
use App\Services\LeagueGroupService;
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
        private readonly LeagueGroupService $groupService,
        private readonly LeagueDrawService $drawService,
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

    public function statistics(string $id): JsonResponse
    {
        try {
            $league = $this->service->show($id);
            $response = $this->statisticsService->build($league);

            return response()->json(['data' => $response], 200, [], JSON_PRESERVE_ZERO_FRACTION);
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

            return response()->json(['data' => $response], 200, [], JSON_PRESERVE_ZERO_FRACTION);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function dashboard(string $id): JsonResponse
    {
        try {
            $league = $this->service->show($id);
            $response = $this->dashboardService->build($league);

            return response()->json(['data' => $response], 200, [], JSON_PRESERVE_ZERO_FRACTION);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function groups(string $id): JsonResponse
    {
        try {
            $league = $this->service->show($id);
            $response = $this->groupService->build($league);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function updateGroups(LeagueGroupsRequest $request, string $id): JsonResponse
    {
        try {
            $response = $this->groupService->assign($id, $request->validated('groups'));

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function shuffleGroups(string $id): JsonResponse
    {
        try {
            $response = $this->groupService->shuffle($id);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function draw(string $id): JsonResponse
    {
        try {
            $league = $this->service->show($id);
            $response = $this->drawService->build($league);

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function drawNextRound(LeagueDrawRoundRequest $request, string $id): JsonResponse
    {
        try {
            $response = $this->drawService->drawNextRound($id, (int) $request->validated('round'));

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function undoLastRound(LeagueDrawRoundRequest $request, string $id): JsonResponse
    {
        try {
            $response = $this->drawService->undoLastRound($id, (int) $request->validated('round'));

            return response()->json(['data' => $response]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function resetDraw(string $id): JsonResponse
    {
        try {
            $response = $this->drawService->reset($id);

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

    public function destroy(LeagueDestroyRequest $request, string $id): JsonResponse
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
