<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelType;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\MailChannelRule;
use App\Models\MailIntake;
use App\Services\MailIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MailIntakeController extends Controller
{
    public function __construct(private MailIntakeService $intakes) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', Rule::in(['pending', 'rejected'])], 'channel_id' => 'sometimes|integer']);
        $user = $request->user();
        $status = $request->string('status')->toString() ?: 'pending';
        $query = MailIntake::query()->with('channel:id,name,type')->where('status', $status)
            ->whereIn('channel_id', Channel::visibleTo($user)->where('type', ChannelType::MAIL)->select('id'));
        if ($request->filled('channel_id')) {
            $query->where('channel_id', $request->integer('channel_id'));
        }
        $items = $query->orderByDesc('received_at')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => collect($items->items())->map(fn (MailIntake $intake) => $this->serialize($intake))->values(), 'meta' => [
            'total' => $items->total(), 'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(),
        ]]);
    }

    public function count(Request $request): JsonResponse
    {
        $user = $request->user();
        $channelIds = Channel::visibleTo($user)->where('type', ChannelType::MAIL)->pluck('id');

        return response()->json(['data' => ['pending' => MailIntake::whereIn('channel_id', $channelIds)->where('status', 'pending')->count()]]);
    }

    public function show(MailIntake $mailIntake): JsonResponse
    {
        $this->authorize('view', $mailIntake->channel);

        return response()->json(['data' => $this->serialize($mailIntake->load('channel:id,name,type'), true)]);
    }

    public function approve(Request $request, MailIntake $mailIntake): JsonResponse
    {
        $this->authorize('view', $mailIntake->channel);
        $message = $this->intakes->approve($mailIntake, $request->user());
        broadcast(new MessageSent($message));
        broadcast(new TenantMessageReceived($message, $message->tenant_id));

        return response()->json(['data' => ['intake' => $this->serialize($mailIntake->fresh()), 'conversation_id' => $message->conversation_id, 'message' => $message]]);
    }

    public function reject(Request $request, MailIntake $mailIntake): JsonResponse
    {
        $this->authorize('view', $mailIntake->channel);

        return response()->json(['data' => $this->serialize($this->intakes->reject($mailIntake, $request->user()))]);
    }

    public function restore(MailIntake $mailIntake): JsonResponse
    {
        $this->authorize('view', $mailIntake->channel);
        abort_unless($mailIntake->status === 'rejected' && (! $mailIntake->expires_at || $mailIntake->expires_at->isFuture()), 422, 'Este email ya no se puede restaurar.');

        return response()->json(['data' => $this->serialize($this->intakes->restore($mailIntake))]);
    }

    public function approveAndAllow(Request $request, MailIntake $mailIntake): JsonResponse
    {
        $this->authorize('update', $mailIntake->channel);
        $this->upsertRule($mailIntake->channel, 'allow', 'email', $mailIntake->from_email);

        return $this->approve($request, $mailIntake);
    }

    public function rejectAndBlock(Request $request, MailIntake $mailIntake): JsonResponse
    {
        $this->authorize('update', $mailIntake->channel);
        $this->upsertRule($mailIntake->channel, 'block', 'email', $mailIntake->from_email);

        return $this->reject($request, $mailIntake);
    }

    public function rules(Channel $channel): JsonResponse
    {
        $this->authorize('view', $channel);
        abort_unless($channel->type === ChannelType::MAIL, 422, 'El canal no es de email.');

        return response()->json(['data' => MailChannelRule::where('channel_id', $channel->id)->orderBy('type')->orderBy('value')->get()]);
    }

    public function storeRule(Request $request, Channel $channel): JsonResponse
    {
        $this->authorize('update', $channel);
        abort_unless($channel->type === ChannelType::MAIL, 422, 'El canal no es de email.');
        $data = $request->validate(['type' => ['required', Rule::in(['allow', 'block'])], 'value_type' => ['required', Rule::in(['email', 'domain'])], 'value' => 'required|string|max:255']);
        $value = strtolower(trim($data['value']));
        if ($data['value_type'] === 'email') {
            validator(['value' => $value], ['value' => 'email'])->validate();
        }
        if ($data['value_type'] === 'domain') {
            validator(['value' => $value], ['value' => ['regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i']])->validate();
        }

        return response()->json(['data' => $this->upsertRule($channel, $data['type'], $data['value_type'], $value)], 201);
    }

    public function destroyRule(Channel $channel, MailChannelRule $mailChannelRule): JsonResponse
    {
        $this->authorize('update', $channel);
        abort_unless($mailChannelRule->channel_id === $channel->id, 404);
        $mailChannelRule->delete();

        return response()->json(['success' => true]);
    }

    private function upsertRule(Channel $channel, string $type, string $valueType, string $value): MailChannelRule
    {
        return MailChannelRule::firstOrCreate(['channel_id' => $channel->id, 'type' => $type, 'value_type' => $valueType, 'value' => strtolower(trim($value))], ['tenant_id' => $channel->tenant_id]);
    }

    private function serialize(MailIntake $intake, bool $detail = false): array
    {
        $data = ['id' => $intake->id, 'channel_id' => $intake->channel_id, 'channel' => $intake->channel, 'status' => $intake->status, 'classification_reason' => $intake->classification_reason, 'from_email' => $intake->from_email, 'from_name' => $intake->from_name, 'subject' => $intake->subject, 'body_text' => $intake->body_text, 'received_at' => $intake->received_at, 'attachments_count' => count($intake->attachments ?? [])];
        if ($detail) {
            $data += ['body_html' => $intake->body_html, 'to' => $intake->to, 'cc' => $intake->cc, 'bcc' => $intake->bcc, 'reply_to' => $intake->reply_to, 'attachments' => $intake->attachments, 'has_remote_images' => $intake->has_remote_images, 'expires_at' => $intake->expires_at];
        }

        return $data;
    }
}
