<?php

namespace App\Http\Controllers;

use App\Models\AiAction;
use App\Models\AiConversation;
use App\Services\AiAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiController extends Controller
{
    public function __construct(protected AiAssistant $ai) {}

    public function index()
    {
        return view('ai.index', [
            'configured' => $this->ai->configured(),
            'models' => AiAssistant::models(),
            'defaultModel' => $this->ai->defaultModel(),
            'visionModel' => config('ai.vision_model'),
            'autoApprove' => $this->ai->autoApprove(auth()->user()),
            'conversations' => AiConversation::query()->where('user_id', auth()->id())->latest('updated_at')->limit(60)->get(),
        ]);
    }

    protected function owned(AiConversation $conversation): AiConversation
    {
        abort_unless($conversation->user_id === auth()->id(), 404);

        return $conversation;
    }

    protected function payload(AiConversation $conversation, array $extra = []): array
    {
        return $extra + [
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->title, 'model' => $this->ai->modelFor($conversation)],
            'messages' => $this->ai->present($conversation),
            'pending' => $conversation->pendingActions()->count(),
        ];
    }

    public function show(AiConversation $conversation)
    {
        return $this->ok('ok', $this->payload($this->owned($conversation)));
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string'],
            'images' => ['nullable', 'array', 'max:'.config('ai.max_images', 4)],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.config('ai.max_image_kb', 8192)],
        ]);
        if (trim((string) ($data['message'] ?? '')) === '' && ! $request->hasFile('images')) {
            return $this->fail('Type a message or attach an image.');
        }

        @set_time_limit(900);
        $model = array_key_exists($data['model'] ?? '', AiAssistant::models()) ? $data['model'] : null;

        $conversation = ! empty($data['conversation_id'])
            ? $this->owned(AiConversation::query()->findOrFail($data['conversation_id']))
            : AiConversation::query()->create([
                'user_id' => auth()->id(),
                'title' => Str::limit(preg_replace('/\s+/', ' ', (string) ($data['message'] ?? '')) ?: 'Image analysis', 60),
                'model' => $model,
            ]);

        if ($conversation->pendingActions()->exists()) {
            return $this->fail('Approve or reject the pending action before sending a new message.', 409, $this->payload($conversation));
        }
        if ($model && $model !== $conversation->model) {
            $conversation->update(['model' => $model]);
        }

        $this->ai->addUserMessage($conversation, (string) ($data['message'] ?? ''), $request->file('images', []));

        try {
            $this->ai->run($conversation, $request->user());
        } catch (\Throwable $e) {
            return $this->fail('AI request failed: '.$e->getMessage(), 502, $this->payload($conversation));
        }

        return $this->ok('ok', $this->payload($conversation->fresh()));
    }

    public function action(Request $request, AiAction $action)
    {
        $conversation = $this->owned($action->conversation);
        $approve = $request->input('decision') === 'approve';

        @set_time_limit(900);
        try {
            $this->ai->resolve($action, $approve, $request->user());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, $this->payload($conversation->fresh()));
        }

        return $this->ok($approve ? 'Action executed' : 'Action rejected', $this->payload($conversation->fresh()));
    }

    public function actionAll(Request $request, AiConversation $conversation)
    {
        $conversation = $this->owned($conversation);
        $approve = $request->input('decision') === 'approve';

        @set_time_limit(1800);
        try {
            $this->ai->resolveAll($conversation, $approve, $request->user());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, $this->payload($conversation->fresh()));
        }

        return $this->ok($approve ? 'All actions executed' : 'All actions rejected', $this->payload($conversation->fresh()));
    }

    public function model(Request $request, AiConversation $conversation)
    {
        $model = (string) $request->input('model');
        abort_unless(array_key_exists($model, AiAssistant::models()), 422, 'Unknown model');
        $this->owned($conversation)->update(['model' => $model]);

        return $this->ok('Model changed to '.AiAssistant::models()[$model]);
    }

    public function image(AiConversation $conversation, string $file)
    {
        $this->owned($conversation);
        abort_unless((bool) preg_match('/^[a-f0-9-]{36}\.(jpe?g|png|webp|gif)$/', $file), 404);
        $path = storage_path('app/ai/'.$conversation->id.'/'.$file);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function destroy(AiConversation $conversation)
    {
        $conversation = $this->owned($conversation);
        $dir = storage_path('app/ai/'.$conversation->id);
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
        $conversation->delete();

        return $this->ok('Conversation deleted');
    }
}
