<?php

namespace App\Http\Controllers;

use App\Models\HandoffNotificationAttempt;
use App\Jobs\SendHumanHandoffNotificationJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppAlertsController extends Controller
{
    public function webhook(Request $request): Response|JsonResponse
    {
        $cfg = config('services.si_crm_alerts');
        if ($request->isMethod('get')) {
            // Meta sends dotted query parameters (hub.mode, hub.verify_token,
            // hub.challenge). Keep the underscore aliases for older/manual
            // integrations, but prefer the canonical Meta names.
            $mode = $request->query('hub.mode', $request->query('hub_mode'));
            $verifyToken = $request->query('hub.verify_token', $request->query('hub_verify_token'));
            $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));
            if ($verifyToken === ($cfg['verify_token'] ?? null) && $mode === 'subscribe') return response($challenge, 200)->header('Content-Type', 'text/plain');
            return response()->json(['error' => 'Verification token mismatch'], 403);
        }
        $secret = $cfg['app_secret'] ?? null;
        $signature = (string) $request->header('X-Hub-Signature-256');
        if ($secret && (! str_starts_with($signature, 'sha256=') || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature))) return response()->json(['error' => 'Invalid signature'], 403);
        foreach ($request->input('entry', []) as $entry) foreach ($entry['changes'] ?? [] as $change) foreach ($change['value']['statuses'] ?? [] as $status) {
            $attempt = HandoffNotificationAttempt::where('external_id', $status['id'] ?? null)->first();
            if (! $attempt) continue;
            $state = $status['status'] ?? 'unknown';
            $updates = ['status' => $state];
            if ($state === 'delivered') $updates['delivered_at'] = now();
            if ($state === 'read') $updates['read_at'] = now();
            if ($state === 'failed') $updates['error'] = data_get($status, 'errors.0.title') ?: data_get($status, 'errors.0.message');
            $attempt->update($updates);
            if ($state === 'failed' && $attempt->destination_type === 'user' && config('services.si_crm_alerts.channel_fallback_enabled')) {
                SendHumanHandoffNotificationJob::dispatch($attempt->human_handoff_id, 'channel');
            }
        }
        return response()->json(['status' => 'EVENT_RECEIVED']);
    }
}
