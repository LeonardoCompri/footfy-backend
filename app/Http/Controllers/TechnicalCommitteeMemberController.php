<?php

namespace App\Http\Controllers;

use App\Http\Requests\TechnicalCommitteeMemberStoreRequest;
use App\Http\Requests\TechnicalCommitteeMemberUpdateRequest;
use App\Http\Resources\TechnicalCommitteeMemberResource;
use App\Services\TechnicalCommitteeMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalCommitteeMemberController extends Controller
{
    public function __construct(private readonly TechnicalCommitteeMemberService $service) {}

    public function index(): JsonResponse
    {
        try {
            $response = $this->service->index();

            return response()->json(['data' => TechnicalCommitteeMemberResource::collection($response)]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function roles(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->roles()]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function store(TechnicalCommitteeMemberStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->store($data);

            return response()->json(['data' => new TechnicalCommitteeMemberResource($response)], 201);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
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

            return response()->json(['data' => new TechnicalCommitteeMemberResource($response)]);
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function update(TechnicalCommitteeMemberUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $response = $this->service->update($data, $id);

            return response()->json(['data' => new TechnicalCommitteeMemberResource($response)]);
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
