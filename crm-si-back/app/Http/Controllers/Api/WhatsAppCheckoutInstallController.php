<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MetaApiException;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppFlow;
use App\Services\Commerce\WhatsAppCheckoutInstaller;
use App\Services\Flows\FlowNameTakenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppCheckoutInstallController extends Controller
{
    public function __invoke(Request $request, WhatsAppCheckoutInstaller $installer): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.manage')) {
            return response()->json(['message' => 'No tenés permiso para gestionar WooCommerce.'], 403);
        }

        try {
            return response()->json(['data' => $installer->install((int) $user->tenant_id)]);
        } catch (FlowNameTakenException) {
            return response()->json(['message' => 'Ya existe otro formulario llamado "'.WhatsAppCheckoutInstaller::FLOW_NAME.'" en la cuenta de WhatsApp. Renombralo y volvé a intentar.'], 422);
        } catch (MetaApiException|InvalidArgumentException|RuntimeException $e) {
            // Errores de validación de Meta sobre el JSON, si el formulario llegó a crearse.
            $errors = WhatsAppFlow::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('system_key', WhatsAppFlow::SYSTEM_CHECKOUT)
                ->get()
                ->flatMap(fn (WhatsAppFlow $flow) => $flow->validation_errors ?? [])
                ->values()
                ->all();

            return response()->json(['message' => $e->getMessage()] + ($errors !== [] ? ['validation_errors' => $errors] : []), 422);
        }
    }
}
