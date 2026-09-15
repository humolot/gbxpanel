<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AiAssistant;
use App\Services\PanelManager;
use App\Services\SystemStats;
use App\Services\TaskRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function __construct(protected PanelManager $panel) {}

    public function index(SystemStats $stats)
    {
        return view('settings.index', [
            'info' => $stats->info(),
            'port' => config('gbx.port'),
            'entry' => config('gbx.entry'),
            'title' => Setting::get('panel_title', 'GBX Panel'),
            'sessionLifetime' => (int) config('session.lifetime'),
            'allowedIps' => Setting::get('allowed_ips', ''),
            'aiConfigured' => (bool) Setting::secret('ai_api_key'),
            'aiModel' => app(AiAssistant::class)->defaultModel(),
            'aiEffort' => Setting::get('ai_effort', 'medium'),
            'aiVision' => Setting::get('ai_vision_model', config('ai.vision_model')),
            'aiAutoApprove' => (bool) Setting::get('ai_auto_approve', 0),
            'models' => AiAssistant::models(),
            'aiTools' => app(\App\Services\Ai\ServerTools::class)->summary(),
            'efforts' => config('ai.reasoning_efforts'),
            'timezone' => $this->panel->currentTimezone(),
            'timezones' => timezone_identifiers_list(),
            'publicIp' => $stats->publicIp(),
            'panelSsl' => (bool) config('gbx.ssl'),
            'panelDomain' => (string) config('gbx.domain'),
            'panelCert' => str_starts_with((string) config('gbx.ssl_cert'), '/etc/letsencrypt/') ? "Let's Encrypt" : 'Self-signed',
        ]);
    }

    public function panel(Request $request)
    {
        $data = $request->validate([
            'panel_title' => ['required', 'string', 'max:40'],
            'port' => ['required', 'integer', 'min:1024', 'max:65535'],
            'entry' => ['nullable', 'string', 'max:32'],
        ]);

        $entry = trim((string) ($data['entry'] ?? ''), '/');
        if (! PanelManager::validEntry($entry)) {
            return $this->fail('The security entrance must be 6-32 characters (letters, numbers, - and _), or empty to disable it.');
        }

        Setting::put('panel_title', $data['panel_title']);
        $messages = ['Settings saved.'];
        $redirect = null;
        $scheme = $request->isSecure() ? 'https' : 'http';

        if ($entry !== (string) config('gbx.entry')) {
            $this->panel->setEnv(['GBX_ENTRY' => $entry]);
            $this->audit('settings', 'Changed security entrance');
            $messages[] = $entry === '' ? 'Security entrance disabled.' : 'New entrance: /'.$entry;
        }

        if ((int) $data['port'] !== (int) config('gbx.port')) {
            $result = $this->panel->changePort((int) $data['port']);
            if ($result->failed()) {
                return $this->fail($result->message());
            }
            $this->audit('settings', 'Changed panel port to '.$data['port']);
            $redirect = $scheme.'://'.$request->getHost().':'.$data['port'].'/'.($entry !== '' ? $entry : '');
            $messages[] = 'The panel is moving to port '.$data['port'].'.';
        }

        return $this->ok(implode(' ', $messages), ['redirect' => $redirect]);
    }

    /** Panel SSL and domain binding are applied by the gbx CLI in a background task. */
    public function access(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:ssl_on,ssl_off,letsencrypt,domain,domain_off'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'email' => ['nullable', 'email'],
        ]);

        $gbx = rtrim(config('gbx.root'), '/').'/bin/gbx';
        $port = config('gbx.port');
        $entry = config('gbx.entry') ? '/'.config('gbx.entry') : '';
        $host = config('gbx.domain') ?: $request->getHost();
        $scheme = config('gbx.ssl') ? 'https' : 'http';

        [$title, $args, $scheme, $host] = match ($data['action']) {
            'ssl_on' => ['Enable panel SSL', 'ssl on', 'https', $host],
            'ssl_off' => ['Disable panel SSL', 'ssl off', 'http', $host],
            'letsencrypt' => ["Let's Encrypt certificate for the panel", 'ssl letsencrypt '.escapeshellarg((string) ($data['email'] ?? '')), 'https', $host],
            'domain' => ['Bind panel to '.($data['domain'] ?? ''), 'domain '.escapeshellarg(strtolower((string) ($data['domain'] ?? ''))), $scheme, strtolower((string) ($data['domain'] ?? ''))],
            'domain_off' => ['Remove panel domain binding', 'domain off', $scheme, app(SystemStats::class)->publicIp()],
        };

        if ($data['action'] === 'domain' && empty($data['domain'])) {
            return $this->fail('Enter the domain to bind.');
        }
        if ($data['action'] === 'letsencrypt') {
            if (! config('gbx.domain')) {
                return $this->fail("Bind a domain to the panel before requesting a Let's Encrypt certificate.");
            }
            if (empty($data['email'])) {
                return $this->fail('Enter an e-mail for Let\'s Encrypt.');
            }
        }

        $this->audit('settings', $title);
        $task = TaskRunner::dispatch($title, $gbx.' '.$args, 'system');

        return $this->ok($title.' started', [
            'task' => ['id' => $task->id, 'title' => $task->title, 'status' => $task->status],
            'redirect' => $scheme.'://'.$host.':'.$port.$entry,
        ]);
    }

    public function security(Request $request)
    {
        $data = $request->validate([
            'session_lifetime' => ['required', 'integer', 'min:5', 'max:10080'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
        ]);

        $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['allowed_ips'] ?? '')))));
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return $this->fail("Invalid IP address: {$ip}");
            }
        }
        if ($ips && ! in_array($request->ip(), $ips, true)) {
            return $this->fail('Your current IP ('.$request->ip().') must be in the allow list, otherwise you would be locked out.');
        }

        Setting::put('allowed_ips', implode(',', $ips));
        $this->panel->setEnv(['SESSION_LIFETIME' => (int) $data['session_lifetime']]);
        $this->audit('settings', 'Updated panel security settings');

        return $this->ok('Security settings saved');
    }

    public function ai(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:300'],
            'model' => ['required', 'in:'.implode(',', array_keys(AiAssistant::models()))],
            'effort' => ['required', 'in:'.implode(',', array_keys(config('ai.reasoning_efforts')))],
            'vision_model' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9._-]+$/i'],
            'auto_approve' => ['nullable', 'boolean'],
            'remove_key' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('remove_key')) {
            Setting::putSecret('ai_api_key', null);
        } elseif (! empty($data['api_key'])) {
            Setting::putSecret('ai_api_key', trim($data['api_key']));
        }
        Setting::put('ai_model', $data['model']);
        Setting::put('ai_effort', $data['effort']);
        Setting::put('ai_vision_model', ($data['vision_model'] ?? '') ?: config('ai.vision_model'));
        Setting::put('ai_auto_approve', $request->boolean('auto_approve'));
        $this->audit('settings', 'Updated AI settings');

        return $this->ok('AI settings saved');
    }

    public function system(Request $request)
    {
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'timezone' => ['required', 'string'],
        ]);

        $info = app(SystemStats::class)->info();
        if ($data['hostname'] !== $info['hostname']) {
            $r = $this->panel->setHostname($data['hostname']);
            if ($r->failed()) {
                return $this->fail($r->message());
            }
        }
        if ($data['timezone'] !== $this->panel->currentTimezone()) {
            $r = $this->panel->setTimezone($data['timezone']);
            if ($r->failed()) {
                return $this->fail($r->message());
            }
        }
        $this->audit('settings', 'Updated system settings', json_encode($data));

        return $this->ok('System settings saved');
    }

    public function action(Request $request)
    {
        $action = (string) $request->input('action');
        Cache::forget('gbx.home.extra');

        return match ($action) {
            'reboot' => $this->result($this->panel->reboot(), 'The server will reboot in a few seconds.', 'system'),
            'restart_panel' => $this->result($this->panel->restartPanel(), 'Panel services are restarting.', 'system'),
            'update_system' => $this->task(TaskRunner::dispatch('Update system packages', $this->panel->updateSystemScript(), 'system'), 'System update started'),
            'clear_cache' => $this->clearCache(),
            default => $this->fail('Unknown action'),
        };
    }

    protected function clearCache()
    {
        Cache::flush();
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        $this->audit('system', 'Cleared panel cache');

        return $this->ok('Panel cache cleared');
    }
}
