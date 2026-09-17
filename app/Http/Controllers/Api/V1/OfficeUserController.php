<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOfficeUserRequest;
use App\Models\User;
use App\Services\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficeUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can list office users.');

        return response()->json(['data' => User::query()->orderBy('name')->get()]);
    }

    public function store(StoreOfficeUserRequest $request, PhoneNumber $phones): JsonResponse
    {
        $validated = $request->validated();
        $validated['phone'] = filled($validated['phone'] ?? null)
            ? $phones->toTanzanianWaId($validated['phone'])
            : null;
        $user = User::create([
            ...$validated,
            'system_role' => $request->enum('system_role', SystemRole::class) ?? SystemRole::Member,
            'is_active' => true,
        ]);

        return response()->json(['data' => $user], 201);
    }
}
