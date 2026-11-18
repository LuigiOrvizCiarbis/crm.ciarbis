<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdReferral;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anuncios de Meta por los que entraron los chats que el usuario puede ver;
 * son las opciones del filtro por anuncio de la bandeja.
 */
class ConversationAdController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $visibleConversationIds = Conversation::query()
            ->visibleTo($request->user())
            ->select('conversations.id');

        // Meta manda el mismo título y tipo en cada entrada de un anuncio: MAX solo elige uno no nulo.
        $ads = AdReferral::query()
            ->whereNotNull('ad_id')
            ->whereIn('conversation_id', $visibleConversationIds)
            ->groupBy('ad_id')
            ->selectRaw('ad_id, MAX(headline) as headline, MAX(source_type) as source_type')
            ->orderByRaw('MAX(referred_at) DESC')
            ->get()
            ->map(fn (AdReferral $ad) => [
                'ad_id' => $ad->ad_id,
                'headline' => $ad->headline,
                'source_type' => $ad->source_type,
            ]);

        return response()->json(['data' => $ads]);
    }
}
