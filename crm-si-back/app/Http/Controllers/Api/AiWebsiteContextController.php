<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WebsiteContextException;
use App\Http\Controllers\Controller;
use App\Services\Ai\WebsiteContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Genera el borrador de "información del sitio" desde la URL del tenant. No
 * guarda nada: el texto vuelve al formulario y se persiste con PUT ai-config.
 */
class AiWebsiteContextController extends Controller
{
    public function __invoke(Request $request, WebsiteContextService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('ai_config.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ]);

        try {
            $result = $service->generate($user->tenant_id, $validated['url']);
        } catch (WebsiteContextException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->status);
        }

        return response()->json(['data' => $result]);
    }
}
