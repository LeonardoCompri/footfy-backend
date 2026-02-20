<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChampionshipStoreRequest;
use App\Services\ChampionshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChampionshipController extends Controller
{
    public function __construct(private readonly ChampionshipService $service) {}

    public function index(): JsonResponse
    {
        $response = $this->service->index();

        return response()->json($response);
    }

    public function store(ChampionshipStoreRequest $request)
    {
        $data = $request->validated();
        $response = $this->service->store($data);

        return response()->json($response, 201);
    }

    public function show(string $id)
    {
        //
    }

    public function update(Request $request, string $id)
    {
        //
    }

    public function destroy(string $id)
    {
        //
    }
}
