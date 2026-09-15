<?php

namespace App\Services;

use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\ServerTools;
use App\Services\Ai\VeniceClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Server administration assistant (Venice AI + function calling).
 *
 * Flow: user message -> model -> tool calls. Read-only tools run right away
 * and the loop continues; tools that change the server become pending
 * AiActions and the loop pauses until the user approves or rejects them.
 */
class AiAssistant
{
    public function __construct(protected VeniceClient $client, protected ServerTools $tools) {}

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public static function models(): array
    {
        return config('ai.models');
    }

    public function defaultModel(): string
    {
        $model = Setting::get('ai_model', config('ai.default_model'));

        return array_key_exists($model, self::models()) ? $model : array_key_first(self::models());
    }

    public function modelFor(AiConversation $conversation): string
    {
        return $conversation->model && array_key_exists($conversation->model, self::models()) ? $conversation->model : $this->defaultModel();
    }

    public function autoApprove(User $user): bool
    {
        return $user->isAdmin() && (bool) Setting::get('ai_auto_approve', 0);
    }

    protected function systemPrompt(User $user): string
    {
        $info = app(SystemStats::class)->info();
        $write = $user->canWrite()
            ? 'You can also call tools that change the server. Those calls are shown to the user as an approval card and only run after the user clicks Approve. Briefly explain what you are about to change and why before calling them. If an action is rejected, do not insist; suggest alternatives.'
            : 'This user has a read-only account: you can inspect the server but not change it. Explain the steps an administrator should take instead.';

        // minute precision keeps the prompt prefix cacheable between tool steps
        $time = now()->format('Y-m-d H:i').' '.config('app.timezone');
        $panelPort = config('gbx.port');

        return <<<TXT
You are GBX AI, the server administrator assistant built into GBX Panel. You operate this Linux host for the user through function tools: monitoring (CPU, memory, disks, network, processes), services, packages and updates, files, backups and restores, websites (Apache with PHP-FPM or reverse proxy), SSL, MySQL/MariaDB, FTP, cron, firewall/SSH/Fail2ban, ClamAV antivirus scans and quarantine, Docker and compose, Supervisor workers, PM2 apps and Git deployments.

Server: {$info['hostname']} ({$info['os']}, kernel {$info['kernel']}, {$info['cores']} CPU cores). Panel port: {$panelPort}. Current time: {$time}.
User: {$user->name} ({$user->role}).

How to work:
- Investigate with tools before answering. Never invent command output, file contents, versions or state.
- Prefer the specific tools over shell commands. Use run_readonly_command for other diagnostics and run_command only when nothing else fits.
- {$write}
- Plan multi-step jobs and execute them step by step (e.g. deploy: check ports and software -> install missing software -> deploy with docker_compose_deploy / git_deploy / pm2_start -> create_website with proxy_target -> open_port only if public access is needed -> manage_website_ssl).
- Long operations (installs, upgrades, backups, deploys, compose, certificates) return a task_id. Follow them with get_task_status (wait_seconds up to 120) and only report success when the task finished with status success; show the relevant error output if it failed.
- Apps behind a website reverse proxy should listen on 127.0.0.1 (e.g. docker ports "127.0.0.1:3000:3000"), so no firewall port needs to be opened.
- Before editing configuration, read it first and prefer edit_file with a unique fragment over rewriting the whole file. Back up (backup_path / backup_website / backup_database) before risky changes and deletions.
- Never claim an action was done until its tool result confirms it. Report failures honestly.
- Protect access: never close SSH or the panel port ({$panelPort}), never stop apache2 / gbxpanel-fpm / supervisor (they run the panel) unless the user insists, and warn before reboots, SSH changes, dropping databases or deleting files.
- Do not reveal passwords or secrets found in files unless the user asks. Passwords you generate for new databases/FTP accounts must be shown to the user.
- Panel conventions: websites /www/wwwroot/<domain>, site logs /www/wwwlogs/<domain>-error.log, vhosts /etc/apache2/sites-available/gbx-<domain>.conf, backups /www/backup, compose projects /www/docker/<project>, PHP-FPM sockets /run/php/php<version>-fpm.sock, panel /usr/local/gbxpanel.
- Answer in the same language the user writes in. Be concise: short paragraphs, lists, fenced code blocks for commands/config, and a short summary of what was changed at the end.
TXT;
    }

    /* ------------------------------------------------------------ messages */

    /**
     * Store the user message (with optional images analysed by the vision model).
     *
     * @param  UploadedFile[]  $images
     */
    public function addUserMessage(AiConversation $conversation, string $text, array $images = []): AiMessage
    {
        $stored = [];
        $dataUrls = [];
        foreach (array_slice($images, 0, (int) config('ai.max_images', 4)) as $file) {
            $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'png');
            $dir = storage_path('app/ai/'.$conversation->id);
            @mkdir($dir, 0775, true);
            $file->move($dir, $name);
            $stored[] = $name;
            $dataUrls[] = 'data:'.(mime_content_type($dir.'/'.$name) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($dir.'/'.$name));
        }

        $content = trim($text);
        $meta = [];
        if ($dataUrls) {
            try {
                $analysis = $this->client->describeImages($dataUrls, $content ?: 'What is shown in this image?');
                $meta['image_analysis'] = $analysis;
                $content .= "\n\n[Attached image(s) analysed by the vision model ".config('ai.vision_model').":]\n".$analysis;
            } catch (\Throwable $e) {
                $meta['image_error'] = $e->getMessage();
                $content .= "\n\n[The user attached image(s) but they could not be analysed: ".$e->getMessage().']';
            }
        }

        return $conversation->messages()->create(['role' => 'user', 'content' => $content, 'images' => $stored ?: null, 'meta' => $meta ?: null]);
    }

    /** Conversation history in OpenAI chat format (valid tool call pairs only). */
    protected function history(AiConversation $conversation): array
    {
        $rows = $conversation->messages()->reorder()->latest('id')->limit(80)->get()->reverse()->values();

        // start at a user message so tool results are never orphaned
        while ($rows->isNotEmpty() && $rows->first()->role !== 'user') {
            $rows->shift();
        }

        $answered = $rows->where('role', 'tool')->pluck('tool_call_id')->filter()->all();
        $messages = [];
        foreach ($rows as $m) {
            if ($m->role === 'assistant') {
                $msg = ['role' => 'assistant', 'content' => $m->content];
                $calls = array_values(array_filter($m->tool_calls ?? [], fn ($c) => in_array($c['id'] ?? null, $answered, true)));
                if ($calls) {
                    $msg['tool_calls'] = $calls;
                }
                if (! empty($m->meta['reasoning_details'])) {
                    $msg['reasoning_details'] = $m->meta['reasoning_details'];
                }
                if ($msg['content'] === null && empty($msg['tool_calls'])) {
                    continue;
                }
                $messages[] = $msg;
            } elseif ($m->role === 'tool') {
                $messages[] = ['role' => 'tool', 'tool_call_id' => $m->tool_call_id, 'content' => (string) $m->content];
            } else {
                $messages[] = ['role' => 'user', 'content' => (string) $m->content];
            }
        }

        return $messages;
    }

    /* ---------------------------------------------------------------- loop */

    /** Run the model until it answers or needs approval. Returns the pending actions. */
    public function run(AiConversation $conversation, User $user): array
    {
        $tools = $this->tools->definitions($user);
        $model = $this->modelFor($conversation);
        $effort = Setting::get('ai_effort', 'medium');
        $options = [
            'reasoning_effort' => array_key_exists($effort, config('ai.reasoning_efforts')) ? $effort : null,
            'cache_key' => 'gbx-'.md5(json_encode($tools)),
            'max_tokens' => 16000,
        ];

        for ($step = 0; $step < (int) config('ai.max_steps', 10); $step++) {
            $response = $this->client->chat($model, array_merge([['role' => 'system', 'content' => $this->systemPrompt($user)]], $this->history($conversation)), $tools, $options);
            $message = $response['message'];

            $calls = [];
            foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                if (! is_array($call) || empty($call['function']['name'])) {
                    continue;
                }
                $call['id'] = ($call['id'] ?? '') ?: 'call_'.Str::random(16);
                $call['type'] = 'function';
                $call['function']['arguments'] = is_string($call['function']['arguments'] ?? null) ? $call['function']['arguments'] : json_encode($call['function']['arguments'] ?? new \stdClass);
                $calls[] = $call;
            }

            $text = VeniceClient::text($message['content'] ?? '');
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $text !== '' ? $text : null,
                'tool_calls' => $calls ?: null,
                'meta' => array_filter([
                    'model' => $model,
                    'usage' => $response['usage'] ?? null,
                    'cost' => $response['cost'] ?? null,
                    'reasoning_details' => $message['reasoning_details'] ?? null,
                    'finish_reason' => $response['finish_reason'],
                ]),
            ]);
            $conversation->touch();

            if (! $calls) {
                if ($text === '') {
                    $assistant->update(['content' => 'No answer was generated. Try again or choose another model.']);
                }

                return [];
            }

            $pending = [];
            foreach ($calls as $call) {
                $name = $call['function']['name'];
                $args = json_decode($call['function']['arguments'], true);
                $args = is_array($args) ? $args : [];

                if (! $this->tools->exists($name) || ! $this->tools->allowed($name, $user)) {
                    $this->storeToolResult($conversation, $call['id'], $name, json_encode(['error' => "Tool {$name} is not available."]));
                } elseif (! $this->tools->isWrite($name) || $this->autoApprove($user)) {
                    $this->storeToolResult($conversation, $call['id'], $name, $this->tools->execute($name, $args, $user));
                    if ($this->tools->isWrite($name)) {
                        AiAction::query()->create(['ai_conversation_id' => $conversation->id, 'ai_message_id' => $assistant->id, 'tool_call_id' => $call['id'], 'tool' => $name, 'arguments' => $args, 'status' => 'done', 'result' => 'auto-approved', 'user_id' => $user->id]);
                    }
                } else {
                    $pending[] = AiAction::query()->create(['ai_conversation_id' => $conversation->id, 'ai_message_id' => $assistant->id, 'tool_call_id' => $call['id'], 'tool' => $name, 'arguments' => $args, 'status' => 'pending']);
                }
            }

            if ($pending) {
                return $pending;
            }
        }

        $conversation->messages()->create(['role' => 'assistant', 'content' => 'I stopped after '.config('ai.max_steps').' tool steps. Tell me to continue if you want me to keep investigating.']);

        return [];
    }

    protected function storeToolResult(AiConversation $conversation, string $callId, string $name, string $result): AiMessage
    {
        return $conversation->messages()->create(['role' => 'tool', 'tool_call_id' => $callId, 'tool_name' => $name, 'content' => $result]);
    }

    /** Approve or reject a pending action; continues the loop when nothing else is pending. */
    public function resolve(AiAction $action, bool $approve, User $user): array
    {
        if ($action->status !== 'pending') {
            throw new \RuntimeException('This action was already handled.');
        }

        $conversation = $action->conversation;
        if ($approve) {
            if (! $this->tools->allowed($action->tool, $user)) {
                throw new \RuntimeException('Your account cannot approve this action.');
            }
            $action->update(['status' => 'approved', 'user_id' => $user->id]);
            $result = $this->tools->execute($action->tool, $action->arguments ?? [], $user);
            $decoded = json_decode($result, true);
            $failed = is_array($decoded) && (isset($decoded['error']) || ($decoded['ok'] ?? true) === false);
            $action->update(['status' => $failed ? 'failed' : 'done', 'result' => Str::limit($result, 20000)]);
        } else {
            $result = json_encode(['rejected' => true, 'message' => 'The user rejected this action. Do not run it again unless the user asks.']);
            $action->update(['status' => 'rejected', 'user_id' => $user->id, 'result' => $result]);
        }

        $this->storeToolResult($conversation, $action->tool_call_id, $action->tool, $result);

        $stillPending = AiAction::query()->where('ai_message_id', $action->ai_message_id)->where('status', 'pending')->exists();

        return $stillPending ? AiAction::query()->where('ai_conversation_id', $conversation->id)->where('status', 'pending')->get()->all() : $this->run($conversation, $user);
    }

    /** Approve or reject every pending action of the conversation, in order. */
    public function resolveAll(AiConversation $conversation, bool $approve, User $user): array
    {
        $pending = [];
        foreach ($conversation->pendingActions()->get() as $action) {
            if ($action->fresh()->status === 'pending') {
                $pending = $this->resolve($action, $approve, $user);
            }
        }

        return $pending;
    }

    /* ------------------------------------------------------------ rendering */

    /** Messages shaped for the chat UI (tool calls folded into their assistant message). */
    public function present(AiConversation $conversation): array
    {
        $messages = $conversation->messages()->get();
        $results = $messages->where('role', 'tool')->keyBy('tool_call_id');
        $actions = $conversation->actions()->get()->keyBy('tool_call_id');

        $out = [];
        foreach ($messages as $m) {
            if ($m->role === 'tool') {
                continue;
            }
            $item = ['id' => $m->id, 'role' => $m->role, 'content' => $m->content];
            if ($m->role === 'user') {
                $item['images'] = collect($m->images ?? [])->map(fn ($f) => route('ai.image', [$conversation, $f]))->all();
                $item['content'] = trim(explode("\n\n[Attached image(s)", (string) $m->content)[0]);
                $item['image_analysis'] = $m->meta['image_analysis'] ?? null;
            } else {
                $item['model'] = $m->meta['model'] ?? null;
                $item['tools'] = collect($m->tool_calls ?? [])->map(function ($call) use ($results, $actions) {
                    $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
                    $action = $actions[$call['id']] ?? null;
                    $result = $results[$call['id']]->content ?? null;
                    $status = $action?->status ?? ($result !== null ? 'done' : 'pending');
                    if ($status === 'done' && $result && str_contains(substr($result, 0, 40), '"error"')) {
                        $status = 'failed';
                    }

                    return [
                        'call_id' => $call['id'],
                        'name' => $call['function']['name'],
                        'label' => $this->tools->label($call['function']['name'], $args),
                        'write' => $this->tools->isWrite($call['function']['name']),
                        'arguments' => $args,
                        'status' => $status,
                        'action_id' => $action?->id,
                        'result' => $result !== null ? Str::limit($result, 4000) : null,
                    ];
                })->all();
            }
            $out[] = $item;
        }

        return $out;
    }
}
