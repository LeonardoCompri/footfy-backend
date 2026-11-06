<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $cpf = preg_replace('/\D/', '', $data['document']);
        $user = User::where('document', $cpf)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'CPF ou senha inválidos',
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        $permissions = $user->getAllPermissions()
            ->unique('id')
            ->map(fn ($permission) => $permission->only(['id', 'name', 'guard_name']))
            ->values();

        $user->roles->each->makeHidden('permissions');
        $user->setRelation('permissions', $permissions);

        $team = $user->linkedTeam();
        $player = $user->player;
        $user->unsetRelation('team')->unsetRelation('player');

        return response()->json([
            'token' => $token,
            'user' => [
                ...$user->toArray(),
                'team' => $team ? ['id' => $team->id, 'name' => $team->name, 'logo' => $team->logoUrl()] : null,
                'player' => $player ? ['id' => $player->id, 'name' => $player->name, 'team_id' => $player->team_id] : null,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logout realizado',
        ]);
    }
}
