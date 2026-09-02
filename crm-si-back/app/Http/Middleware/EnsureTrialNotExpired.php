<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTrialNotExpired
{
    /**
     * Bloquea el acceso a la API cuando el trial del tenant venció. El endpoint
     * de usuario y el logout quedan exentos para que el frontend siempre pueda
     * leer el estado del trial y cerrar sesión. Cambiar la contraseña y
     * revocar sesiones también quedan exentos: son justo lo que alguien con
     * una cuenta vencida querría poder hacer (por ejemplo, si sospecha que
     * alguien más tiene acceso).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant === null) {
            return $next($request);
        }

        if ($request->is('api/logout')
            || ($request->is('api/user') && $request->isMethod('get'))
            || $request->is('api/profile/password')
            || $request->is('api/profile/sessions*')
        ) {
            return $next($request);
        }

        if ($user->tenant->trialExpired()) {
            return response()->json([
                'error' => 'trial_expired',
                'message' => 'Tu período de prueba ha finalizado.',
                'trial_ends_at' => $user->tenant->trial_ends_at,
            ], 402);
        }

        return $next($request);
    }
}
