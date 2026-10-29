<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfilePlayerUpdateRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\UserResource;
use App\Services\PlayerService;
use App\Services\UserService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

// Conta do usuário autenticado (/me), para qualquer papel.
class ProfileController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly PlayerService $playerService,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $response = $this->userService->showProfile();

            return response()->json(['data' => new UserResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        try {
            $response = $this->userService->updateProfile($request->validated());

            return response()->json(['data' => new UserResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }

    public function updatePlayer(ProfilePlayerUpdateRequest $request): JsonResponse
    {
        try {
            $response = $this->playerService->updateOwnPlayer($request->validated());

            return response()->json(['data' => new PlayerResource($response)]);
        } catch (ModelNotFoundException $ex) {
            // Usuário sem jogador vinculado (ex.: coach, admin, player sem vínculo).
            return response()->json(['message' => $ex->getMessage()], 404);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json(['message' => 'Houve um erro no servidor, tente novamente!'], 500);
        }
    }
}
