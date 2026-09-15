<?php

namespace App\Http\Controllers;

use App\Services\FileManager;
use App\Services\Shell;
use App\Services\SystemStats;
use App\Services\TerminalManager;
use Illuminate\Http\Request;

/**
 * Web terminal. The primary mode is a real-time PTY session served by the gbx-terminal
 * daemon over WebSocket (see TerminalManager). When the daemon is not running, the page
 * falls back to command mode: each command runs in a fresh root bash with the working
 * directory persisted in the session.
 */
class TerminalController extends Controller
{
    protected const INTERACTIVE = ['vim', 'vi', 'nano', 'top', 'htop', 'less', 'more', 'watch', 'mysql', 'ssh', 'ftp', 'sftp', 'man', 'tmux', 'screen', 'python', 'python3', 'node', 'php -a', 'mc', 'bash', 'sh'];

    public function index(SystemStats $stats, TerminalManager $terminal)
    {
        return view('terminal.index', [
            'cwd' => session('terminal_cwd', '/root'),
            'hostname' => $stats->info()['hostname'],
            'live' => $terminal->available(),
            'wsUrl' => $terminal->url(),
        ]);
    }

    /** Issue a single-use token for a real-time session. */
    public function token(Request $request, TerminalManager $terminal)
    {
        $data = $request->validate([
            'cols' => ['nullable', 'integer', 'min:10', 'max:500'],
            'rows' => ['nullable', 'integer', 'min:2', 'max:200'],
        ]);

        if (! $terminal->available()) {
            return $this->fail('The terminal service is not running. Start it on the server with: gbx terminal', 503);
        }

        $this->audit('terminal', 'Opened terminal session');

        return $this->ok('ok', [
            'token' => $terminal->token($request->user(), $request->ip(), (int) ($data['cols'] ?? 120), (int) ($data['rows'] ?? 32)),
            'url' => $terminal->url(),
        ]);
    }

    public function exec(Request $request)
    {
        $data = $request->validate(['command' => ['required', 'string', 'max:4000']]);
        $command = trim($data['command']);
        $cwd = session('terminal_cwd', '/root');

        $first = strtok($command, " \t");
        if (in_array($command, self::INTERACTIVE, true) || in_array($first, ['vim', 'vi', 'nano', 'top', 'htop', 'less', 'more', 'watch', 'tmux', 'screen', 'mc'], true)) {
            return $this->ok('ok', [
                'output' => "'{$first}' is interactive and cannot run in the web console.\nTip: use the Files editor, 'ps aux --sort=-%cpu | head', 'cat file' or 'tail -n 100 file'.\n",
                'cwd' => $cwd, 'exit' => 1,
            ]);
        }

        $this->audit('terminal', 'Executed command', mb_substr($command, 0, 1000));

        if (Shell::simulating()) {
            if (preg_match('/^cd\s*(.*)$/', $command, $m)) {
                $target = $m[1] === '' || $m[1] === '~' ? '/root' : (str_starts_with($m[1], '/') ? $m[1] : $cwd.'/'.$m[1]);
                session(['terminal_cwd' => FileManager::normalize($target)]);

                return $this->ok('ok', ['output' => '', 'cwd' => session('terminal_cwd'), 'exit' => 0]);
            }

            return $this->ok('ok', ['output' => "[simulation] {$command}\n", 'cwd' => $cwd, 'exit' => 0]);
        }

        $marker = '__GBX_CWD_'.bin2hex(random_bytes(4)).'__';
        $script = 'cd '.Shell::arg($cwd).' 2>/dev/null || cd /root; export TERM=dumb HOME=/root; '.$command."\n__gbx_exit=\$?; echo; echo \"{$marker}\$(pwd)\"; exit \$__gbx_exit";
        $result = Shell::run($script, (int) $request->input('timeout', 120) > 0 ? min(600, (int) $request->input('timeout', 120)) : 120);

        $output = $result->output.$result->error;
        $newCwd = $cwd;
        if (($pos = strrpos($output, $marker)) !== false) {
            $newCwd = trim(strtok(substr($output, $pos + strlen($marker)), "\n"));
            $output = rtrim(substr($output, 0, $pos), "\n")."\n";
        }
        if ($output === "\n") {
            $output = '';
        }
        if (strlen($output) > 500000) {
            $output = "... output truncated, showing last 500 KB ...\n".substr($output, -500000);
        }
        session(['terminal_cwd' => $newCwd ?: $cwd]);

        return $this->ok('ok', [
            'output' => mb_convert_encoding(preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $output), 'UTF-8', 'UTF-8'),
            'cwd' => $newCwd ?: $cwd,
            'exit' => $result->exitCode,
        ]);
    }
}
