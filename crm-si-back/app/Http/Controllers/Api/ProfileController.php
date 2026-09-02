<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Acciones self-service sobre el propio usuario autenticado. A diferencia de
 * UserController (gestión de miembros del equipo por un admin), estos
 * endpoints operan siempre sobre $request->user() y nunca requieren permisos
 * Spatie: UserPolicy::update exige 'users.update', que un Member no tiene,
 * y le impediría editar su propio perfil. Mismo criterio que POST
 * /api/email/change.
 */
class ProfileController extends Controller
{
    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['es', 'en'];

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:100'],
        ]);

        $user->forceFill($validated)->save();

        return response()->json(['data' => new UserResource($user)]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        // Cambiar la contraseña es, casi siempre, una respuesta a haber
        // querido expulsar a alguien más: revocar todo excepto la sesión
        // actual, nunca la que está haciendo el cambio.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when(
            $currentTokenId !== null,
            fn ($query) => $query->where('id', '!=', $currentTokenId)
        )->delete();

        return response()->json(['message' => 'Contraseña actualizada. Se cerraron las demás sesiones.']);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', self::SUPPORTED_LOCALES)],
            'timezone' => ['required', 'string', 'timezone'],
            'date_format' => ['required', 'string', 'max:20'],
        ]);

        $user->forceFill(['preferences' => $validated])->save();

        return response()->json(['data' => ['preferences' => $user->preferencesWithDefaults()]]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=4000,max_height=4000',
            ],
        ]);

        $previousPath = $user->avatar_path;

        $path = $validated['avatar']->store('avatars/'.$user->tenant_id, 'public');
        $user->forceFill(['avatar_path' => $path])->save();

        if ($previousPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        return response()->json(['data' => ['avatar_url' => $user->avatarUrl()]]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return response()->json([], 204);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'is_current' => $token->id === $currentTokenId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        if ($tokenId === $currentTokenId) {
            return response()->json([
                'message' => 'No podés cerrar la sesión actual desde acá. Usá "Cerrar sesión".',
            ], 422);
        }

        // El id de personal_access_tokens es secuencial y global: sin filtrar
        // por el dueño del token, cualquier usuario autenticado podría
        // revocar la sesión de otro (IDOR).
        $deleted = $user->tokens()->where('id', $tokenId)->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Sesión no encontrada.'], 404);
        }

        return response()->json([], 204);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $user->tokens()->when(
            $currentTokenId !== null,
            fn ($query) => $query->where('id', '!=', $currentTokenId)
        )->delete();

        return response()->json([], 204);
    }
}
