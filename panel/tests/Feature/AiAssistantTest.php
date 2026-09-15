<?php

namespace Tests\Feature;

use App\Models\AiAction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(string $role = 'admin'): User
    {
        Setting::putSecret('ai_api_key', 'test-key');

        return User::query()->create(['name' => 'Admin', 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
    }

    protected function toolCall(string $id, string $name, array $args = []): array
    {
        return ['choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => null,
            'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]],
        ]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]];
    }

    protected function answer(string $text): array
    {
        return ['choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]];
    }

    public function test_read_tools_run_automatically_and_request_is_well_formed(): void
    {
        Http::fakeSequence('api.venice.ai/*')
            ->push($this->toolCall('call_1', 'get_server_overview'))
            ->push($this->answer('The server is healthy.'));

        $response = $this->actingAs($this->admin())->postJson('/ai/send', ['message' => 'How is the server?', 'model' => 'qwen-3-8-flash']);

        $response->assertOk()->assertJsonPath('pending', 0);
        $this->assertSame('The server is healthy.', collect($response->json('messages'))->last()['content']);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.venice.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $body['model'] === 'qwen-3-8-flash'
                && $body['venice_parameters']['include_venice_system_prompt'] === false
                && collect($body['tools'])->pluck('function.name')->contains('get_server_overview')
                && $body['messages'][0]['role'] === 'system';
        });
        // the second request carries the tool result paired with its call
        Http::assertSent(fn (Request $r) => collect($r->data()['messages'])->contains(fn ($m) => $m['role'] === 'tool' && $m['tool_call_id'] === 'call_1'));
    }

    public function test_write_tools_wait_for_approval_then_continue(): void
    {
        Http::fakeSequence('api.venice.ai/*')
            ->push($this->toolCall('call_9', 'service_action', ['service' => 'apache2', 'action' => 'restart']))
            ->push($this->answer('Apache was restarted.'));

        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/ai/send', ['message' => 'Restart apache']);
        $response->assertOk()->assertJsonPath('pending', 1);
        Http::assertSentCount(1);

        $action = AiAction::query()->firstOrFail();
        $this->assertSame('pending', $action->status);

        // new messages are blocked while an action is pending
        $this->actingAs($admin)->postJson('/ai/send', ['message' => 'hello', 'conversation_id' => $action->ai_conversation_id])->assertStatus(409);

        $this->actingAs($admin)->postJson('/ai/actions/'.$action->id, ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('pending', 0);

        $this->assertSame('done', $action->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_rejected_action_is_reported_to_the_model(): void
    {
        Http::fakeSequence('api.venice.ai/*')
            ->push($this->toolCall('call_x', 'run_command', ['command' => 'reboot', 'reason' => 'test']))
            ->push($this->answer('Understood, I will not reboot.'));

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/ai/send', ['message' => 'reboot please'])->assertJsonPath('pending', 1);
        $action = AiAction::query()->firstOrFail();

        $this->actingAs($admin)->postJson('/ai/actions/'.$action->id, ['decision' => 'reject'])->assertOk();

        $this->assertSame('rejected', $action->fresh()->status);
        Http::assertSent(fn (Request $r) => collect($r->data()['messages'])->contains(fn ($m) => $m['role'] === 'tool' && str_contains($m['content'], 'rejected')));
    }

    public function test_viewer_only_receives_read_tools(): void
    {
        Http::fake(['api.venice.ai/*' => Http::response($this->answer('ok'))]);

        $this->actingAs($this->admin('viewer'))->postJson('/ai/send', ['message' => 'hi'])->assertOk();

        Http::assertSent(function (Request $r) {
            $names = collect($r->data()['tools'])->pluck('function.name');

            return $names->contains('list_websites') && ! $names->contains('run_command') && ! $names->contains('service_action');
        });
    }

    public function test_auto_approved_tool_reuses_panel_controllers(): void
    {
        Http::fakeSequence('api.venice.ai/*')
            ->push($this->toolCall('call_w', 'create_website', ['domain' => 'ai-site.com', 'php_version' => '8.4']))
            ->push($this->answer('Website created.'));

        $admin = $this->admin();
        Setting::put('ai_auto_approve', 1);

        $this->actingAs($admin)->postJson('/ai/send', ['message' => 'create ai-site.com'])->assertOk()->assertJsonPath('pending', 0);

        $this->assertDatabaseHas('websites', ['domain' => 'ai-site.com', 'php_version' => '8.4']);
        $this->assertDatabaseHas('ai_actions', ['tool' => 'create_website', 'status' => 'done']);
    }

    public function test_readonly_command_guard_blocks_changes(): void
    {
        $tools = app(\App\Services\Ai\ServerTools::class);

        $this->assertNull($tools->rejectReadonly('systemctl status apache2'));
        $this->assertNotNull($tools->rejectReadonly('systemctl stop apache2'));
        $this->assertNotNull($tools->rejectReadonly('cat /etc/hosts; reboot'));
        $this->assertNotNull($tools->rejectReadonly('docker rm -f web'));
    }

    public function test_api_errors_are_returned_to_the_ui(): void
    {
        Http::fake(['api.venice.ai/*' => Http::response(['error' => 'Insufficient balance'], 402)]);

        $this->actingAs($this->admin())->postJson('/ai/send', ['message' => 'hi'])
            ->assertStatus(502)
            ->assertJsonPath('ok', false);
    }
}
