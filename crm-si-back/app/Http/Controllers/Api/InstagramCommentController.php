<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstagramComment;
use App\Services\InstagramCommentService;
use Illuminate\Http\Request;

class InstagramCommentController extends Controller
{
    public function __construct(private InstagramCommentService $service) {}

    public function index(Request $request)
    {
        $query = InstagramComment::with(['channel', 'contact', 'assignedUser'])->visibleTo($request->user());
        foreach (['status', 'visibility', 'channel_id', 'assigned_to'] as $filter) if ($request->filled($filter)) $query->where($filter, $request->input($filter));
        return response()->json($query->latest('commented_at')->paginate((int) $request->input('per_page', 50)));
    }

    public function show(Request $request, InstagramComment $instagramComment)
    {
        $this->authorizeComment($request, $instagramComment);
        return response()->json(['data' => $instagramComment->load(['channel', 'contact', 'conversation', 'assignedUser'])]);
    }

    public function assign(Request $request, InstagramComment $instagramComment)
    {
        $this->authorizeComment($request, $instagramComment);
        $data = $request->validate(['assigned_to' => 'nullable|exists:users,id', 'status' => 'nullable|in:new,in_progress,resolved']);
        $instagramComment->update($data);
        return response()->json(['data' => $instagramComment->fresh(['assignedUser'])]);
    }

    public function publicReply(Request $request, InstagramComment $instagramComment)
    {
        $this->authorizeComment($request, $instagramComment);
        $data = $request->validate(['text' => 'required|string|max:2000']);
        return response()->json(['data' => $this->service->replyPublicly($instagramComment, $data['text'], $request->user())]);
    }

    public function privateReply(Request $request, InstagramComment $instagramComment)
    {
        $this->authorizeComment($request, $instagramComment);
        $data = $request->validate(['text' => 'required|string|max:2000']);
        return response()->json(['data' => $this->service->replyPrivately($instagramComment, $data['text'], $request->user())]);
    }

    public function hide(Request $request, InstagramComment $instagramComment)
    {
        $this->authorizeComment($request, $instagramComment);
        return response()->json(['data' => $this->service->setVisibility($instagramComment, true, $request->user())]);
    }

    public function unhide(Request $request, InstagramComment $instagramComment)
    {
        $this->authorizeComment($request, $instagramComment);
        return response()->json(['data' => $this->service->setVisibility($instagramComment, false, $request->user())]);
    }

    public function destroy(Request $request, InstagramComment $instagramComment)
    {
        $this->authorizeComment($request, $instagramComment);
        return response()->json(['data' => $this->service->delete($instagramComment, $request->user())]);
    }

    private function authorizeComment(Request $request, InstagramComment $comment): void
    {
        abort_unless((int) $comment->tenant_id === (int) $request->user()->tenant_id, 404);
        abort_unless($comment->visibleTo($request->user())->whereKey($comment->id)->exists(), 403);
    }
}
