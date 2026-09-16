<?php

namespace App\Http\Controllers;

use App\Services\MysqlManager;
use App\Services\Shell;
use App\Services\Tuning\ApacheTuner;
use App\Services\Tuning\MysqlTuner;
use App\Services\Tuning\PhpTuner;
use App\Services\Tuning\Tuner;
use Illuminate\Http\Request;

/**
 * Performance settings of PHP-FPM, Apache and MySQL: current values, values proposed for the
 * memory of this server, and a status panel with advice.
 */
class TuningController extends Controller
{
    protected function tuner(string $target): Tuner
    {
        $tuner = match (true) {
            $target === 'apache' => new ApacheTuner,
            $target === 'mysql' => new MysqlTuner(app(MysqlManager::class)),
            (bool) preg_match('/^php(\d+\.\d+)$/', $target, $m) && \App\Services\PhpManager::validVersion($m[1]) => new PhpTuner($m[1]),
            default => abort(404),
        };
        abort_unless($tuner->installed(), 404, 'This component is not installed on the server.');

        return $tuner;
    }

    public function show(string $target)
    {
        $tuner = $this->tuner($target);
        $values = $tuner->values();

        // values proposed for every plan, so the form fills in without another call
        $suggestions = [];
        foreach (array_keys(Tuner::PLANS) as $plan) {
            $suggestions[$plan] = $tuner->suggest(Tuner::planRamMb($plan));
        }

        $groups = [];
        foreach ($tuner->fields() as $key => $field) {
            $groups[$field['group'] ?? 'Settings'][$key] = $field + ['value' => $values[$key] ?? ''];
        }

        return $this->ok('ok', [
            'target' => $target,
            'label' => $tuner->label(),
            'file' => $tuner->file(),
            'groups' => $groups,
            'values' => $values,
            'plans' => collect(Tuner::PLANS)->map(fn ($plan, $key) => ['key' => $key, 'label' => $plan['label']])->values(),
            'suggestions' => $suggestions,
            'ram_mb' => Tuner::totalRamMb(),
            'status' => $tuner->status(),
            'restartable' => $target === 'mysql',
            'functions' => $tuner instanceof PhpTuner ? ['current' => $tuner->disabledFunctions(), 'recommended' => PhpTuner::RECOMMENDED_DISABLED] : null,
            'process_mb' => $tuner instanceof PhpTuner ? $tuner->processMb() : null,
        ]);
    }

    public function save(Request $request, string $target)
    {
        $tuner = $this->tuner($target);
        $values = (array) $request->input('values', []);

        try {
            $result = $tuner->apply($values);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $this->audit('software', 'Changed the performance settings of '.$tuner->label(), implode(', ', array_keys($values)));

        return $this->ok(trim($result->output) ?: $tuner->label().' settings applied');
    }

    /** Restart the database so the settings that need it take effect. */
    public function restart(string $target)
    {
        $tuner = $this->tuner($target);
        if (! $tuner instanceof MysqlTuner) {
            return $this->fail('Use the Services page to restart this component.');
        }
        $result = $tuner->restart();
        $this->audit('software', 'Restarted the database after a settings change');

        return $result->failed() ? $this->fail($result->message()) : $this->ok('The database was restarted with the new settings.');
    }

    /* ==================================================== disabled functions */

    public function functions(Request $request, string $target)
    {
        $tuner = $this->tuner($target);
        if (! $tuner instanceof PhpTuner) {
            return $this->fail('Only PHP has disabled functions.');
        }
        $functions = (array) $request->input('functions', []);
        if (count($functions) > 200) {
            return $this->fail('Too many functions.');
        }
        $result = $tuner->saveDisabledFunctions($functions);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $this->audit('software', 'Changed the disabled functions of '.$tuner->label(), implode(', ', $functions));

        return $this->ok(count($functions).' function(s) blocked in '.$tuner->label());
    }

    /** Components that can be tuned on this server, for the Software page. */
    public static function targets(): array
    {
        $targets = [];
        foreach (PhpTuner::versions() as $version) {
            $targets['php'.$version] = 'PHP '.$version;
        }
        if (Shell::simulating() || Shell::fileExists(ApacheTuner::MAIN)) {
            $targets['apache'] = 'Apache';
        }
        if (app(MysqlManager::class)->installed()) {
            $targets['mysql'] = 'MySQL / MariaDB';
        }

        return $targets;
    }
}
