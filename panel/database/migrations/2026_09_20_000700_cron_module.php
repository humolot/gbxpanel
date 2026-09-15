<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cron_jobs', function (Blueprint $table) {
            // shell, site_backup, db_backup, path_backup, log_cut, url, free_memory, script, laravel, flow
            $table->string('type', 30)->default('shell')->after('name');
            $table->json('params')->nullable()->after('command');
            // one or more execution cycles, rendered to cron expressions
            $table->json('cycles')->nullable()->after('schedule');
            $table->unsignedInteger('keep')->nullable()->after('run_as');
            $table->string('notes')->nullable()->after('keep');
            $table->smallInteger('last_status')->nullable()->after('last_run_at');
            $table->unsignedInteger('last_duration')->nullable()->after('last_status');
        });

        Schema::create('cron_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 20)->default('custom'); // service, process, alarm, load, website, other, custom
            $table->string('language', 10)->default('bash');   // bash, python3, php
            $table->text('content');
            $table->string('remark')->nullable();
            $table->string('success_match')->nullable();
            $table->string('args_hint')->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('last_task_id')->nullable();
            $table->timestamps();
        });

        $now = now();
        $mysql = 'UNIT=mysql; systemctl list-unit-files | grep -q "^mariadb.service" && UNIT=mariadb';
        $scripts = [
            // service management
            ['Restart Apache', 'service', "systemctl restart apache2 && echo done", 'If the output contains done, it indicates success', 'done', null],
            ['Reload Apache', 'service', "apache2ctl configtest && systemctl reload apache2 && echo done", 'Tests the configuration first. If the output contains done, it indicates success', 'done', null],
            ['Get Apache Status', 'service', "if systemctl is-active --quiet apache2; then echo running; else echo \"stopped ($(systemctl is-active apache2))\"; fi", "If the output contains 'stopped', Apache is not running", 'running', null],
            ['Restart MySQL', 'service', "{$mysql}\nsystemctl restart \"\$UNIT\" && echo done", 'If the output contains done, it indicates success', 'done', null],
            ['Start MySQL', 'service', "{$mysql}\nsystemctl start \"\$UNIT\" && echo Starting done", 'If the output contains Starting, it indicates success', 'Starting', null],
            ['Get MySQL Status', 'service', "{$mysql}\nif systemctl is-active --quiet \"\$UNIT\"; then echo running; else echo \"stopped (\$(systemctl is-active \"\$UNIT\"))\"; fi", "If the output contains 'stopped', MySQL is not running", 'running', null],
            ['Restart PHP-FPM', 'service', "systemctl restart \"php\${1:-8.4}-fpm\" && echo done", 'If the output contains done, it indicates success (enter the PHP version)', 'done', 'PHP version, e.g. 8.4'],
            ['Reload PHP-FPM', 'service', "systemctl reload \"php\${1:-8.4}-fpm\" && echo done", 'If the output contains done, it indicates success (enter the PHP version)', 'done', 'PHP version, e.g. 8.4'],
            ['Restart Redis', 'service', "systemctl restart redis-server && echo done", 'If the output contains done, it indicates success', 'done', null],
            ['Restart Supervisor programs', 'service', "supervisorctl restart all && echo done", 'Restarts every Supervisor program (queue workers)', 'done', null],
            // process monitor
            ['Keep a service running', 'process', "if systemctl is-active --quiet \"\$1\"; then echo \"running \$1\"; else systemctl restart \"\$1\"; echo \"restarted \$1\"; fi", "Restarts the service when it is stopped. The output contains 'restarted' when it was down", 'running', 'Service name, e.g. apache2'],
            ['Check a process', 'process', "if pgrep -f \"\$1\" >/dev/null; then echo \"running\"; else echo \"stopped\"; fi", "If the output contains 'stopped', the process is not running", 'running', 'Process name or command line'],
            // alarm notification
            ['Disk usage alert', 'alarm', "USAGE=\$(df -P / | awk 'NR==2 {gsub(\"%\",\"\",\$5); print \$5}')\nif [ \"\$USAGE\" -ge \"\${1:-90}\" ]; then echo \"ALERT disk usage \${USAGE}%\"; else echo \"ok disk usage \${USAGE}%\"; fi", "If the output contains ALERT, the root disk is above the limit", 'ok', 'Limit in percent, default 90'],
            ['Memory usage alert', 'alarm', "USAGE=\$(free | awk '/^Mem:/ {printf \"%d\", (\$2-\$7)*100/\$2}')\nif [ \"\$USAGE\" -ge \"\${1:-90}\" ]; then echo \"ALERT memory usage \${USAGE}%\"; else echo \"ok memory usage \${USAGE}%\"; fi", "If the output contains ALERT, memory usage is above the limit", 'ok', 'Limit in percent, default 90'],
            ['Failed services alert', 'alarm', "FAILED=\$(systemctl --failed --no-legend --plain | awk '{print \$1}')\nif [ -n \"\$FAILED\" ]; then echo \"ALERT failed units: \$FAILED\"; else echo \"ok\"; fi", "If the output contains ALERT, a systemd unit has failed", 'ok', null],
            // load monitoring
            ['CPU load alert', 'load', "awk -v limit=\"\${1:-1.5}\" -v cores=\"\$(nproc)\" '{ if (\$1 / cores > limit) print \"ALERT load \" \$1 \" on \" cores \" cores\"; else print \"ok load \" \$1 }' /proc/loadavg", "If the output contains ALERT, the 1-minute load per core is above the limit", 'ok', 'Load per CPU core, default 1.5'],
            ['Top processes', 'load', "ps -eo pid,user,%cpu,%mem,etime,comm --sort=-%cpu | head -n 15", 'Lists the processes using the most CPU', null, null],
            // website monitoring
            ['Check website status', 'website', "CODE=\$(curl -s -o /dev/null -m 20 -w '%{http_code}' \"\$1\")\nif [ \"\$CODE\" -ge 200 ] && [ \"\$CODE\" -lt 400 ]; then echo \"ok \$CODE\"; else echo \"ALERT \$1 returned \$CODE\"; fi", "If the output contains ALERT, the website did not answer with 2xx/3xx", 'ok', 'URL, e.g. https://example.com'],
            ['SSL certificate expiry', 'website', "END=\$(echo | openssl s_client -servername \"\$1\" -connect \"\$1:443\" 2>/dev/null | openssl x509 -noout -enddate | cut -d= -f2)\nDAYS=\$(( (\$(date -d \"\$END\" +%s) - \$(date +%s)) / 86400 ))\nif [ \"\$DAYS\" -lt \"\${2:-15}\" ]; then echo \"ALERT \$1 certificate expires in \$DAYS days\"; else echo \"ok \$DAYS days\"; fi", "If the output contains ALERT, the certificate expires soon", 'ok', 'Domain and days, e.g. example.com 15'],
            // other
            ['Clean temporary files', 'other', "find /tmp -xdev -type f -mtime +\${1:-7} -delete 2>/dev/null\necho done", 'Deletes files in /tmp older than N days (default 7)', 'done', 'Days, default 7'],
            ['Vacuum systemd journal', 'other', "journalctl --vacuum-size=\${1:-200M} && echo done", 'Keeps the journal below the given size (default 200M)', 'done', 'Size, default 200M'],
            ['List pending updates', 'other', "apt-get update -qq >/dev/null 2>&1\napt list --upgradable 2>/dev/null | tail -n +2", 'Packages that can be upgraded', null, null],
            ['Release memory cache', 'other', "sync && echo 3 > /proc/sys/vm/drop_caches && free -m && echo done", 'Drops the page cache (does not affect running programs)', 'done', null],
        ];

        foreach ($scripts as [$name, $category, $content, $remark, $match, $hint]) {
            DB::table('cron_scripts')->insert([
                'name' => $name, 'category' => $category, 'language' => 'bash', 'content' => $content, 'remark' => $remark,
                'success_match' => $match, 'args_hint' => $hint, 'is_builtin' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_scripts');
        Schema::table('cron_jobs', function (Blueprint $table) {
            $table->dropColumn(['type', 'params', 'cycles', 'keep', 'notes', 'last_status', 'last_duration']);
        });
    }
};
