<?php

use App\Http\Controllers;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication & security entrance
|--------------------------------------------------------------------------
*/

$entry = trim((string) config('gbx.entry'), '/');
if ($entry !== '') {
    Route::get('/'.$entry, fn () => redirect()->route('login'))->name('entrance');
}

Route::middleware('guest')->group(function () {
    Route::get('/login', [Controllers\AuthController::class, 'show'])->name('login');
    Route::post('/login', [Controllers\AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
});
Route::post('/logout', [Controllers\AuthController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Panel
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'panel.access'])->group(function () {

    // Home
    Route::get('/', [Controllers\DashboardController::class, 'index'])->name('home');
    Route::get('/stats', [Controllers\DashboardController::class, 'stats'])->name('home.stats');
    Route::get('/stats/overview', [Controllers\DashboardController::class, 'overview'])->name('home.overview');

    Route::prefix('home')->name('home.')->group(function () {
        Route::get('/monitor', [Controllers\MonitorController::class, 'index'])->name('monitor');
        Route::get('/monitor/data', [Controllers\MonitorController::class, 'data'])->name('monitor.data');

        Route::get('/processes', [Controllers\ProcessController::class, 'index'])->name('processes');
        Route::get('/processes/data', [Controllers\ProcessController::class, 'data'])->name('processes.data');
        Route::post('/processes/kill', [Controllers\ProcessController::class, 'kill'])->name('processes.kill');

        Route::get('/services', [Controllers\ServiceController::class, 'index'])->name('services');
        Route::get('/services/data', [Controllers\ServiceController::class, 'data'])->name('services.data');
        Route::post('/services/action', [Controllers\ServiceController::class, 'action'])->name('services.action');
        Route::get('/services/journal', [Controllers\ServiceController::class, 'journal'])->name('services.journal');

        Route::get('/software', [Controllers\SoftwareController::class, 'index'])->name('software');
        Route::get('/software/data', [Controllers\SoftwareController::class, 'data'])->name('software.data');
        Route::post('/software/install', [Controllers\SoftwareController::class, 'install'])->name('software.install');
        Route::post('/software/uninstall', [Controllers\SoftwareController::class, 'uninstall'])->name('software.uninstall');
        Route::get('/software/php/{version}', [Controllers\SoftwareController::class, 'php'])->name('software.php');
        Route::post('/software/php/{version}', [Controllers\SoftwareController::class, 'phpSave'])->name('software.php.save');
        Route::post('/software/php/{version}/extension', [Controllers\SoftwareController::class, 'phpExtension'])->name('software.php.extension');

        Route::get('/cron', [Controllers\CronController::class, 'index'])->name('cron');
        Route::post('/cron', [Controllers\CronController::class, 'store'])->name('cron.store');
        Route::get('/cron/export', [Controllers\CronController::class, 'export'])->name('cron.export');
        Route::post('/cron/import', [Controllers\CronController::class, 'import'])->name('cron.import');
        Route::post('/cron/bulk', [Controllers\CronController::class, 'bulk'])->name('cron.bulk');
        Route::post('/cron/scripts', [Controllers\CronController::class, 'scriptStore'])->name('cron.scripts.store');
        Route::get('/cron/scripts/{script}', [Controllers\CronController::class, 'scriptShow'])->name('cron.scripts.show');
        Route::put('/cron/scripts/{script}', [Controllers\CronController::class, 'scriptUpdate'])->name('cron.scripts.update');
        Route::delete('/cron/scripts/{script}', [Controllers\CronController::class, 'scriptDestroy'])->name('cron.scripts.destroy');
        Route::post('/cron/scripts/{script}/run', [Controllers\CronController::class, 'scriptRun'])->name('cron.scripts.run');
        Route::get('/cron/scripts/{script}/log', [Controllers\CronController::class, 'scriptLog'])->name('cron.scripts.log');
        Route::get('/cron/{cron}', [Controllers\CronController::class, 'show'])->name('cron.show')->whereNumber('cron');
        Route::put('/cron/{cron}', [Controllers\CronController::class, 'update'])->name('cron.update');
        Route::delete('/cron/{cron}', [Controllers\CronController::class, 'destroy'])->name('cron.destroy');
        Route::post('/cron/{cron}/toggle', [Controllers\CronController::class, 'toggle'])->name('cron.toggle');
        Route::post('/cron/{cron}/run', [Controllers\CronController::class, 'run'])->name('cron.run');
        Route::get('/cron/{cron}/log', [Controllers\CronController::class, 'log'])->name('cron.log');
        Route::delete('/cron/{cron}/log', [Controllers\CronController::class, 'clearLog'])->name('cron.log.clear');
    });

    // Background tasks
    Route::get('/tasks', [Controllers\TaskController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/{task}', [Controllers\TaskController::class, 'show'])->name('tasks.show');

    // Websites
    Route::get('/websites', [Controllers\WebsiteController::class, 'index'])->name('websites.index');
    Route::post('/websites', [Controllers\WebsiteController::class, 'store'])->name('websites.store');
    Route::get('/websites/stats', [Controllers\WebsiteSettingsController::class, 'stats'])->name('websites.stats');
    Route::post('/websites/bulk', [Controllers\WebsiteSettingsController::class, 'bulk'])->name('websites.bulk');
    Route::get('/websites/{website}', [Controllers\WebsiteController::class, 'show'])->name('websites.show');
    Route::put('/websites/{website}', [Controllers\WebsiteController::class, 'update'])->name('websites.update');
    Route::delete('/websites/{website}', [Controllers\WebsiteController::class, 'destroy'])->name('websites.destroy');
    Route::post('/websites/{website}/status', [Controllers\WebsiteController::class, 'status'])->name('websites.status');
    Route::get('/websites/{website}/config', [Controllers\WebsiteController::class, 'config'])->name('websites.config');
    Route::post('/websites/{website}/config', [Controllers\WebsiteController::class, 'saveConfig'])->name('websites.config.save');
    Route::post('/websites/{website}/ssl/letsencrypt', [Controllers\WebsiteController::class, 'sslIssue'])->name('websites.ssl.issue');
    Route::post('/websites/{website}/ssl/custom', [Controllers\WebsiteController::class, 'sslCustom'])->name('websites.ssl.custom');
    Route::post('/websites/{website}/ssl/disable', [Controllers\WebsiteController::class, 'sslDisable'])->name('websites.ssl.disable');
    Route::post('/websites/{website}/ssl/force', [Controllers\WebsiteController::class, 'sslForce'])->name('websites.ssl.force');
    Route::get('/websites/{website}/logs', [Controllers\WebsiteSettingsController::class, 'logs'])->name('websites.logs');
    Route::post('/websites/{website}/logs/clear', [Controllers\WebsiteSettingsController::class, 'clearLog'])->name('websites.logs.clear');
    Route::get('/websites/{website}/usage', [Controllers\WebsiteSettingsController::class, 'usage'])->name('websites.usage');
    Route::post('/websites/{website}/meta', [Controllers\WebsiteSettingsController::class, 'meta'])->name('websites.meta');
    Route::get('/websites/{website}/manage', [Controllers\WebsiteSettingsController::class, 'manage'])->name('websites.manage');
    Route::get('/websites/{website}/manage/{section}', [Controllers\WebsiteSettingsController::class, 'data'])->name('websites.manage.data');
    Route::post('/websites/{website}/manage/{section}', [Controllers\WebsiteSettingsController::class, 'update'])->name('websites.manage.update');
    Route::get('/websites/{website}/backups', [Controllers\WebsiteSettingsController::class, 'backups'])->name('websites.backups');
    Route::post('/websites/{website}/backups', [Controllers\WebsiteSettingsController::class, 'backupCreate'])->name('websites.backups.create');
    Route::post('/websites/{website}/backups/restore', [Controllers\WebsiteSettingsController::class, 'backupRestore'])->name('websites.backups.restore');
    Route::post('/websites/{website}/backups/delete', [Controllers\WebsiteSettingsController::class, 'backupDelete'])->name('websites.backups.delete');
    Route::get('/websites/{website}/backups/download', [Controllers\WebsiteSettingsController::class, 'backupDownload'])->name('websites.backups.download');

    // FTP
    Route::get('/ftp', [Controllers\FtpController::class, 'index'])->name('ftp.index');
    Route::post('/ftp', [Controllers\FtpController::class, 'store'])->name('ftp.store');
    Route::put('/ftp/{ftp}', [Controllers\FtpController::class, 'update'])->name('ftp.update');
    Route::post('/ftp/{ftp}/toggle', [Controllers\FtpController::class, 'toggle'])->name('ftp.toggle');
    Route::delete('/ftp/{ftp}', [Controllers\FtpController::class, 'destroy'])->name('ftp.destroy');

    // Databases
    Route::get('/databases', [Controllers\DatabaseController::class, 'index'])->name('databases.index');
    Route::get('/databases/live', [Controllers\DatabaseController::class, 'live'])->name('databases.live');
    Route::post('/databases', [Controllers\DatabaseController::class, 'store'])->name('databases.store');
    Route::post('/databases/sync', [Controllers\DatabaseController::class, 'sync'])->name('databases.sync');
    Route::post('/databases/sync-users', [Controllers\DatabaseController::class, 'syncUsers'])->name('databases.sync.users');
    Route::post('/databases/root-password', [Controllers\DatabaseController::class, 'rootPassword'])->name('databases.root');
    Route::post('/databases/auto-backup', [Controllers\DatabaseController::class, 'autoBackup'])->name('databases.autobackup');
    Route::post('/databases/bulk', [Controllers\DatabaseController::class, 'bulk'])->name('databases.bulk');
    Route::post('/databases/mongodb/auth', [Controllers\DatabaseController::class, 'mongoAuth'])->name('databases.mongo.auth');
    Route::get('/databases/open/{tool}', [Controllers\DatabaseController::class, 'openTool'])->name('databases.tool');
    Route::post('/databases/tools-access', [Controllers\DatabaseController::class, 'toolsAccess'])->name('databases.tools.access');
    Route::post('/databases/servers', [Controllers\DatabaseController::class, 'serverStore'])->name('databases.servers.store');
    Route::delete('/databases/servers/{server}', [Controllers\DatabaseController::class, 'serverDestroy'])->name('databases.servers.destroy');
    Route::get('/databases/recycle', [Controllers\DatabaseController::class, 'recycle'])->name('databases.recycle');
    Route::post('/databases/recycle/{item}/restore', [Controllers\DatabaseController::class, 'recycleRestore'])->name('databases.recycle.restore');
    Route::delete('/databases/recycle/{item}', [Controllers\DatabaseController::class, 'recycleDestroy'])->name('databases.recycle.destroy');
    Route::get('/databases/backups/{engine}/{file}', [Controllers\DatabaseController::class, 'downloadBackup'])->name('databases.backups.download');
    Route::delete('/databases/backups/{engine}/{file}', [Controllers\DatabaseController::class, 'deleteBackup'])->name('databases.backups.delete');

    // Redis
    Route::get('/databases/redis/overview', [Controllers\RedisController::class, 'overview'])->name('redis.overview');
    Route::post('/databases/redis/connect', [Controllers\RedisController::class, 'connect'])->name('redis.connect');
    Route::get('/databases/redis/keys', [Controllers\RedisController::class, 'keys'])->name('redis.keys');
    Route::get('/databases/redis/key', [Controllers\RedisController::class, 'show'])->name('redis.key');
    Route::post('/databases/redis/key', [Controllers\RedisController::class, 'store'])->name('redis.key.store');
    Route::post('/databases/redis/delete', [Controllers\RedisController::class, 'destroy'])->name('redis.key.delete');
    Route::post('/databases/redis/expire', [Controllers\RedisController::class, 'expire'])->name('redis.key.expire');
    Route::post('/databases/redis/flush', [Controllers\RedisController::class, 'flush'])->name('redis.flush');
    Route::post('/databases/redis/config', [Controllers\RedisController::class, 'configure'])->name('redis.config');
    Route::get('/databases/redis/backups', [Controllers\RedisController::class, 'backups'])->name('redis.backups');
    Route::post('/databases/redis/backups', [Controllers\RedisController::class, 'backup'])->name('redis.backup');
    Route::post('/databases/redis/restore', [Controllers\RedisController::class, 'restore'])->name('redis.restore');
    Route::get('/databases/redis/backups/{file}', [Controllers\RedisController::class, 'download'])->name('redis.backups.download');
    Route::delete('/databases/redis/backups/{file}', [Controllers\RedisController::class, 'deleteBackup'])->name('redis.backups.delete');

    // Qdrant
    Route::get('/databases/qdrant/overview', [Controllers\QdrantController::class, 'overview'])->name('qdrant.overview');
    Route::post('/databases/qdrant/settings', [Controllers\QdrantController::class, 'settings'])->name('qdrant.settings');
    Route::post('/databases/qdrant/collections', [Controllers\QdrantController::class, 'store'])->name('qdrant.store');
    Route::get('/databases/qdrant/collections/{name}', [Controllers\QdrantController::class, 'show'])->name('qdrant.show');
    Route::delete('/databases/qdrant/collections/{name}', [Controllers\QdrantController::class, 'destroy'])->name('qdrant.destroy');
    Route::get('/databases/qdrant/collections/{name}/points', [Controllers\QdrantController::class, 'points'])->name('qdrant.points');
    Route::get('/databases/qdrant/collections/{name}/snapshots', [Controllers\QdrantController::class, 'snapshots'])->name('qdrant.snapshots');
    Route::post('/databases/qdrant/collections/{name}/snapshots', [Controllers\QdrantController::class, 'createSnapshot'])->name('qdrant.snapshots.create');
    Route::post('/databases/qdrant/collections/{name}/restore', [Controllers\QdrantController::class, 'restoreSnapshot'])->name('qdrant.snapshots.restore');
    Route::get('/databases/qdrant/collections/{name}/snapshots/{snapshot}', [Controllers\QdrantController::class, 'downloadSnapshot'])->name('qdrant.snapshots.download');
    Route::delete('/databases/qdrant/collections/{name}/snapshots/{snapshot}', [Controllers\QdrantController::class, 'deleteSnapshot'])->name('qdrant.snapshots.delete');

    Route::post('/databases/{database}/password', [Controllers\DatabaseController::class, 'password'])->name('databases.password');
    Route::post('/databases/{database}/permission', [Controllers\DatabaseController::class, 'permission'])->name('databases.permission');
    Route::get('/databases/{database}/credentials', [Controllers\DatabaseController::class, 'credentials'])->name('databases.credentials');
    Route::get('/databases/{database}/tables', [Controllers\DatabaseController::class, 'tables'])->name('databases.tables');
    Route::post('/databases/{database}/tables', [Controllers\DatabaseController::class, 'tablesAction'])->name('databases.tables.action');
    Route::post('/databases/{database}/meta', [Controllers\DatabaseController::class, 'meta'])->name('databases.meta');
    Route::get('/databases/{database}/backups', [Controllers\DatabaseController::class, 'backups'])->name('databases.backups');
    Route::post('/databases/{database}/backup', [Controllers\DatabaseController::class, 'backup'])->name('databases.backup');
    Route::post('/databases/{database}/restore', [Controllers\DatabaseController::class, 'restore'])->name('databases.restore');
    Route::post('/databases/{database}/import', [Controllers\DatabaseController::class, 'import'])->name('databases.import');
    Route::delete('/databases/{database}', [Controllers\DatabaseController::class, 'destroy'])->name('databases.destroy');

    // Docker
    Route::prefix('docker')->name('docker.')->group(function () {
        Route::get('/', [Controllers\DockerController::class, 'index'])->name('index');
        Route::get('/overview', [Controllers\DockerController::class, 'overview'])->name('overview');
        Route::get('/stats', [Controllers\DockerController::class, 'stats'])->name('stats');

        Route::get('/containers', [Controllers\DockerController::class, 'containers'])->name('containers');
        Route::get('/containers/detail/{ref}', [Controllers\DockerController::class, 'container'])->name('containers.show');
        Route::post('/containers', [Controllers\DockerController::class, 'create'])->name('create');
        Route::post('/containers/action', [Controllers\DockerController::class, 'action'])->name('action');
        Route::post('/containers/bulk', [Controllers\DockerController::class, 'bulk'])->name('containers.bulk');
        Route::post('/containers/rename', [Controllers\DockerController::class, 'rename'])->name('containers.rename');
        Route::post('/containers/update', [Controllers\DockerController::class, 'update'])->name('containers.update');
        Route::post('/containers/commit', [Controllers\DockerController::class, 'commit'])->name('containers.commit');
        Route::get('/containers/logs', [Controllers\DockerController::class, 'logs'])->name('logs');
        Route::get('/containers/log-sizes', [Controllers\DockerController::class, 'logSizes'])->name('containers.logsizes');
        Route::post('/containers/clear-logs', [Controllers\DockerController::class, 'clearLogs'])->name('containers.clearlogs');
        Route::get('/containers/inspect', [Controllers\DockerController::class, 'inspect'])->name('inspect');
        Route::post('/containers/prune', [Controllers\DockerController::class, 'pruneContainers'])->name('containers.prune');
        Route::post('/notes', [Controllers\DockerController::class, 'note'])->name('note');

        Route::get('/images', [Controllers\DockerController::class, 'images'])->name('images');
        Route::post('/images/pull', [Controllers\DockerController::class, 'pull'])->name('pull');
        Route::post('/images/import', [Controllers\DockerController::class, 'import'])->name('images.import');
        Route::post('/images/build', [Controllers\DockerController::class, 'build'])->name('images.build');
        Route::post('/images/push', [Controllers\DockerController::class, 'push'])->name('images.push');
        Route::post('/images/export', [Controllers\DockerController::class, 'export'])->name('images.export');
        Route::post('/images/remove', [Controllers\DockerController::class, 'removeImage'])->name('images.remove');
        Route::post('/images/bulk-remove', [Controllers\DockerController::class, 'bulkImages'])->name('images.bulk');
        Route::post('/images/prune', [Controllers\DockerController::class, 'pruneImages'])->name('images.prune');

        Route::get('/networks', [Controllers\DockerController::class, 'networks'])->name('networks');
        Route::post('/networks', [Controllers\DockerController::class, 'networkStore'])->name('networks.store');
        Route::post('/networks/remove', [Controllers\DockerController::class, 'networkDestroy'])->name('networks.remove');
        Route::post('/networks/prune', [Controllers\DockerController::class, 'pruneNetworks'])->name('networks.prune');

        Route::get('/volumes', [Controllers\DockerController::class, 'volumes'])->name('volumes');
        Route::post('/volumes', [Controllers\DockerController::class, 'volumeStore'])->name('volumes.store');
        Route::post('/volumes/remove', [Controllers\DockerController::class, 'volumeDestroy'])->name('volumes.remove');
        Route::post('/volumes/prune', [Controllers\DockerController::class, 'pruneVolumes'])->name('volumes.prune');

        Route::get('/compose', [Controllers\DockerComposeController::class, 'index'])->name('compose.index');
        Route::post('/compose', [Controllers\DockerComposeController::class, 'store'])->name('compose.store');
        Route::post('/compose/remove', [Controllers\DockerComposeController::class, 'destroy'])->name('compose.remove');
        Route::get('/compose/templates', [Controllers\DockerComposeController::class, 'templates'])->name('compose.templates');
        Route::post('/compose/templates', [Controllers\DockerComposeController::class, 'templateStore'])->name('compose.templates.store');
        Route::put('/compose/templates/{template}', [Controllers\DockerComposeController::class, 'templateUpdate'])->name('compose.templates.update');
        Route::delete('/compose/templates/{template}', [Controllers\DockerComposeController::class, 'templateDestroy'])->name('compose.templates.destroy');
        Route::get('/compose/{name}', [Controllers\DockerComposeController::class, 'show'])->name('compose.show');
        Route::get('/compose/{name}/logs', [Controllers\DockerComposeController::class, 'logs'])->name('compose.logs');
        Route::post('/compose/{name}/action', [Controllers\DockerComposeController::class, 'action'])->name('compose.action');
        Route::post('/compose/{name}/file', [Controllers\DockerComposeController::class, 'saveFile'])->name('compose.file');

        Route::get('/apps', [Controllers\DockerStoreController::class, 'apps'])->name('apps');
        Route::post('/apps/{slug}/install', [Controllers\DockerStoreController::class, 'install'])->name('apps.install');
        Route::get('/hub/search', [Controllers\DockerStoreController::class, 'hubSearch'])->name('hub.search');
        Route::get('/hub/tags', [Controllers\DockerStoreController::class, 'hubTags'])->name('hub.tags');

        Route::get('/registries', [Controllers\DockerSettingsController::class, 'registries'])->name('registries');
        Route::post('/registries', [Controllers\DockerSettingsController::class, 'registryStore'])->name('registries.store');
        Route::put('/registries/{registry}', [Controllers\DockerSettingsController::class, 'registryUpdate'])->name('registries.update');
        Route::delete('/registries/{registry}', [Controllers\DockerSettingsController::class, 'registryDestroy'])->name('registries.destroy');
        Route::post('/registries/{registry}/test', [Controllers\DockerSettingsController::class, 'registryTest'])->name('registries.test');
        Route::get('/settings', [Controllers\DockerSettingsController::class, 'settings'])->name('settings');
        Route::post('/settings', [Controllers\DockerSettingsController::class, 'saveSettings'])->name('settings.save');
        Route::post('/service', [Controllers\DockerSettingsController::class, 'service'])->name('service');
    });

    // Security
    Route::get('/security', [Controllers\SecurityController::class, 'index'])->name('security.index');
    Route::get('/security/data', [Controllers\SecurityController::class, 'data'])->name('security.data');
    Route::post('/security/firewall/toggle', [Controllers\SecurityController::class, 'toggle'])->name('security.toggle');
    Route::post('/security/firewall/rules', [Controllers\SecurityController::class, 'addRule'])->name('security.rules.store');
    Route::delete('/security/firewall/rules/{number}', [Controllers\SecurityController::class, 'deleteRule'])->name('security.rules.destroy');
    Route::post('/security/firewall/block', [Controllers\SecurityController::class, 'block'])->name('security.block');
    Route::post('/security/ssh', [Controllers\SecurityController::class, 'ssh'])->name('security.ssh');
    Route::get('/security/ssh/failed', [Controllers\SecurityController::class, 'failed'])->name('security.failed');
    Route::get('/security/antivirus', [Controllers\AntivirusController::class, 'index'])->name('security.antivirus');
    Route::get('/security/antivirus/scans', [Controllers\AntivirusController::class, 'scans'])->name('security.antivirus.scans');
    Route::get('/security/antivirus/scans/{scan}', [Controllers\AntivirusController::class, 'showScan'])->name('security.antivirus.scan.show');
    Route::post('/security/antivirus/scan', [Controllers\AntivirusController::class, 'scan'])->name('security.antivirus.scan');
    Route::post('/security/antivirus/scan/website/{website}', [Controllers\AntivirusController::class, 'scanWebsite'])->name('security.antivirus.scan.website');
    Route::post('/security/antivirus/detections', [Controllers\AntivirusController::class, 'action'])->name('security.antivirus.action');
    Route::post('/security/antivirus/settings', [Controllers\AntivirusController::class, 'settings'])->name('security.antivirus.settings');
    Route::post('/security/antivirus/signatures', [Controllers\AntivirusController::class, 'updateSignatures'])->name('security.antivirus.signatures');
    Route::post('/security/fail2ban/unban',[Controllers\SecurityController::class, 'unban'])->name('security.unban');

    // Files
    Route::get('/files', [Controllers\FileController::class, 'index'])->name('files.index');
    Route::get('/files/list', [Controllers\FileController::class, 'list'])->name('files.list');
    Route::get('/files/editor', [Controllers\FileController::class, 'editor'])->name('files.editor');
    Route::get('/files/open', [Controllers\FileController::class, 'open'])->name('files.open');
    Route::get('/files/search', [Controllers\FileController::class, 'search'])->name('files.search');
    Route::post('/files/write', [Controllers\FileController::class, 'write'])->name('files.write');
    Route::post('/files/create', [Controllers\FileController::class, 'create'])->name('files.create');
    Route::post('/files/rename', [Controllers\FileController::class, 'rename'])->name('files.rename');
    Route::post('/files/delete', [Controllers\FileController::class, 'delete'])->name('files.delete');
    Route::post('/files/paste', [Controllers\FileController::class, 'paste'])->name('files.paste');
    Route::post('/files/permissions', [Controllers\FileController::class, 'permissions'])->name('files.permissions');
    Route::post('/files/compress', [Controllers\FileController::class, 'compress'])->name('files.compress');
    Route::post('/files/extract', [Controllers\FileController::class, 'extract'])->name('files.extract');
    Route::post('/files/upload', [Controllers\FileController::class, 'upload'])->name('files.upload');
    Route::get('/files/download', [Controllers\FileController::class, 'download'])->name('files.download');
    Route::get('/files/size', [Controllers\FileController::class, 'size'])->name('files.size');

    // Logs
    Route::get('/logs', [Controllers\LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/read', [Controllers\LogController::class, 'read'])->name('logs.read');
    Route::get('/logs/activity', [Controllers\LogController::class, 'activity'])->name('logs.activity');
    Route::post('/logs/clear', [Controllers\LogController::class, 'clear'])->name('logs.clear');

    // AI
    Route::get('/ai', [Controllers\AiController::class, 'index'])->name('ai.index');
    Route::get('/ai/conversations/{conversation}', [Controllers\AiController::class, 'show'])->name('ai.show');
    Route::post('/ai/send', [Controllers\AiController::class, 'send'])->name('ai.send');
    Route::post('/ai/actions/{action}', [Controllers\AiController::class, 'action'])->name('ai.action');
    Route::post('/ai/conversations/{conversation}/actions', [Controllers\AiController::class, 'actionAll'])->name('ai.action.all');
    Route::post('/ai/conversations/{conversation}/model', [Controllers\AiController::class, 'model'])->name('ai.model');
    Route::get('/ai/conversations/{conversation}/images/{file}', [Controllers\AiController::class, 'image'])->name('ai.image');
    Route::delete('/ai/conversations/{conversation}', [Controllers\AiController::class, 'destroy'])->name('ai.destroy');

    // Own profile
    Route::post('/profile/password', [Controllers\AccountController::class, 'password'])->name('profile.password');

    // Administrators only
    Route::middleware('panel.access:admin')->group(function () {
        Route::get('/terminal', [Controllers\TerminalController::class, 'index'])->name('terminal.index');
        Route::post('/terminal/token', [Controllers\TerminalController::class, 'token'])->name('terminal.token');
        Route::post('/terminal/exec', [Controllers\TerminalController::class, 'exec'])->name('terminal.exec');

        Route::get('/accounts', [Controllers\AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [Controllers\AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{user}', [Controllers\AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{user}', [Controllers\AccountController::class, 'destroy'])->name('accounts.destroy');

        Route::get('/settings', [Controllers\SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/panel', [Controllers\SettingsController::class, 'panel'])->name('settings.panel');
        Route::post('/settings/access', [Controllers\SettingsController::class, 'access'])->name('settings.access');
        Route::post('/settings/security',[Controllers\SettingsController::class, 'security'])->name('settings.security');
        Route::post('/settings/ai', [Controllers\SettingsController::class, 'ai'])->name('settings.ai');
        Route::post('/settings/system', [Controllers\SettingsController::class, 'system'])->name('settings.system');
        Route::post('/settings/action', [Controllers\SettingsController::class, 'action'])->name('settings.action');
    });
});
