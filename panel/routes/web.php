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
    Route::get('/login/two-factor', [Controllers\AuthController::class, 'twoFactor'])->name('login.two-factor');
    Route::post('/login/two-factor', [Controllers\AuthController::class, 'verifyTwoFactor'])->middleware('throttle:10,1')->name('login.two-factor.verify');
});
Route::post('/logout', [Controllers\AuthController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Client sub-panel (hosting customers, guard "client")
|--------------------------------------------------------------------------
*/

Route::prefix('client')->name('client.')->group(function () {
    Route::middleware('guest:client')->group(function () {
        Route::get('/login', [Controllers\Client\ClientAuthController::class, 'show'])->name('login');
        Route::post('/login', [Controllers\Client\ClientAuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
        Route::get('/login/two-factor', [Controllers\Client\ClientAuthController::class, 'twoFactor'])->name('login.two-factor');
        Route::post('/login/two-factor', [Controllers\Client\ClientAuthController::class, 'verifyTwoFactor'])->middleware('throttle:10,1')->name('login.two-factor.verify');
    });
    Route::post('/logout', [Controllers\Client\ClientAuthController::class, 'logout'])->name('logout');

    Route::middleware('client.access')->group(function () {
        Route::get('/', [Controllers\Client\ClientDashboardController::class, 'index'])->name('home');
        Route::get('/overview', [Controllers\Client\ClientDashboardController::class, 'data'])->name('overview');
        Route::get('/tasks', [Controllers\Client\ClientDashboardController::class, 'tasks'])->name('tasks');
        Route::get('/tasks/{task}', [Controllers\Client\ClientDashboardController::class, 'taskShow'])->name('tasks.show');

        Route::get('/websites', [Controllers\Client\ClientWebsiteController::class, 'index'])->name('websites');
        Route::post('/websites', [Controllers\Client\ClientWebsiteController::class, 'store'])->name('websites.store');
        Route::post('/websites/{website}/status', [Controllers\Client\ClientWebsiteController::class, 'status'])->name('websites.status');
        Route::post('/websites/{website}/php', [Controllers\Client\ClientWebsiteController::class, 'php'])->name('websites.php');
        Route::post('/websites/{website}/ssl', [Controllers\Client\ClientWebsiteController::class, 'ssl'])->name('websites.ssl');
        Route::get('/websites/{website}/logs', [Controllers\Client\ClientWebsiteController::class, 'logs'])->name('websites.logs');
        Route::delete('/websites/{website}', [Controllers\Client\ClientWebsiteController::class, 'destroy'])->name('websites.destroy');

        Route::get('/ftp', [Controllers\Client\ClientFtpController::class, 'index'])->name('ftp');
        Route::post('/ftp', [Controllers\Client\ClientFtpController::class, 'store'])->name('ftp.store');
        Route::post('/ftp/{ftp}/password', [Controllers\Client\ClientFtpController::class, 'password'])->name('ftp.password');
        Route::post('/ftp/{ftp}/toggle', [Controllers\Client\ClientFtpController::class, 'toggle'])->name('ftp.toggle');
        Route::delete('/ftp/{ftp}', [Controllers\Client\ClientFtpController::class, 'destroy'])->name('ftp.destroy');

        Route::get('/databases', [Controllers\Client\ClientDatabaseController::class, 'index'])->name('databases');
        Route::post('/databases', [Controllers\Client\ClientDatabaseController::class, 'store'])->name('databases.store');
        Route::get('/databases/{database}/credentials', [Controllers\Client\ClientDatabaseController::class, 'credentials'])->name('databases.credentials');
        Route::post('/databases/{database}/password', [Controllers\Client\ClientDatabaseController::class, 'password'])->name('databases.password');
        Route::delete('/databases/{database}', [Controllers\Client\ClientDatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::get('/databases/{database}/phpmyadmin', [Controllers\Client\ClientDatabaseController::class, 'phpMyAdmin'])->name('databases.phpmyadmin');
        Route::get('/databases/{database}/backups', [Controllers\Client\ClientDatabaseController::class, 'backups'])->name('databases.backups');
        Route::post('/databases/{database}/backup', [Controllers\Client\ClientDatabaseController::class, 'backup'])->name('databases.backup');
        Route::post('/databases/{database}/restore', [Controllers\Client\ClientDatabaseController::class, 'restore'])->name('databases.restore');
        Route::get('/databases/{database}/download', [Controllers\Client\ClientDatabaseController::class, 'downloadBackup'])->name('databases.download');
        Route::post('/databases/{database}/delete-backup', [Controllers\Client\ClientDatabaseController::class, 'deleteBackup'])->name('databases.backups.delete');
        Route::get('/databases/{database}/export', [Controllers\Client\ClientDatabaseController::class, 'export'])->name('databases.export');
        Route::post('/databases/{database}/import', [Controllers\Client\ClientDatabaseController::class, 'import'])->name('databases.import');

        Route::get('/files', [Controllers\Client\ClientFileController::class, 'index'])->name('files');
        Route::get('/files/list', [Controllers\Client\ClientFileController::class, 'list'])->name('files.list');
        Route::get('/files/read', [Controllers\Client\ClientFileController::class, 'read'])->name('files.read');
        Route::get('/files/editor', [Controllers\Client\ClientFileController::class, 'editor'])->name('files.editor');
        Route::get('/files/open', [Controllers\Client\ClientFileController::class, 'open'])->name('files.open');
        Route::get('/files/search', [Controllers\Client\ClientFileController::class, 'search'])->name('files.search');
        Route::post('/files/write', [Controllers\Client\ClientFileController::class, 'write'])->name('files.write');
        Route::post('/files/create', [Controllers\Client\ClientFileController::class, 'create'])->name('files.create');
        Route::post('/files/rename', [Controllers\Client\ClientFileController::class, 'rename'])->name('files.rename');
        Route::post('/files/delete', [Controllers\Client\ClientFileController::class, 'delete'])->name('files.delete');
        Route::post('/files/paste', [Controllers\Client\ClientFileController::class, 'paste'])->name('files.paste');
        Route::post('/files/upload', [Controllers\Client\ClientFileController::class, 'upload'])->name('files.upload');
        Route::get('/files/download', [Controllers\Client\ClientFileController::class, 'download'])->name('files.download');
        Route::post('/files/extract', [Controllers\Client\ClientFileController::class, 'extract'])->name('files.extract');
        Route::post('/files/compress', [Controllers\Client\ClientFileController::class, 'compress'])->name('files.compress');

        Route::get('/account/security', [Controllers\Client\ClientSecurityController::class, 'show'])->name('account.security');
        Route::post('/account/password', [Controllers\Client\ClientSecurityController::class, 'password'])->middleware('throttle:10,1')->name('account.password');
        Route::post('/account/two-factor/setup', [Controllers\Client\ClientSecurityController::class, 'setup'])->middleware('throttle:10,1')->name('account.2fa.setup');
        Route::post('/account/two-factor/confirm', [Controllers\Client\ClientSecurityController::class, 'confirm'])->middleware('throttle:10,1')->name('account.2fa.confirm');
        Route::post('/account/two-factor/recovery-codes', [Controllers\Client\ClientSecurityController::class, 'recoveryCodes'])->middleware('throttle:10,1')->name('account.2fa.recovery');
        Route::post('/account/two-factor/disable', [Controllers\Client\ClientSecurityController::class, 'disable'])->middleware('throttle:10,1')->name('account.2fa.disable');
        Route::post('/account/two-factor/forget-browser', [Controllers\Client\ClientSecurityController::class, 'forgetBrowser'])->name('account.2fa.forget');
    });
});

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

    // DNS (provider APIs)
    Route::prefix('dns')->name('dns.')->group(function () {
        Route::get('/', [Controllers\DnsController::class, 'index'])->name('index');
        Route::get('/providers', [Controllers\DnsController::class, 'providers'])->name('providers');
        Route::get('/zones', [Controllers\DnsController::class, 'zones'])->name('zones');
        Route::post('/zones/sync', [Controllers\DnsController::class, 'syncAll'])->name('zones.sync');
        Route::get('/zones/{zone}/records', [Controllers\DnsController::class, 'records'])->name('records');
        Route::post('/zones/{zone}/records', [Controllers\DnsController::class, 'recordStore'])->name('records.store');
        Route::put('/zones/{zone}/records/{record}', [Controllers\DnsController::class, 'recordUpdate'])->name('records.update');
        Route::delete('/zones/{zone}/records/{record}', [Controllers\DnsController::class, 'recordDestroy'])->name('records.destroy');
        Route::post('/zones/{zone}/point', [Controllers\DnsController::class, 'point'])->name('point');
        Route::get('/match', [Controllers\DnsController::class, 'match'])->name('match');
        Route::get('/lookup', [Controllers\DnsController::class, 'lookup'])->name('lookup');

        // API credentials: administrators only
        Route::middleware('panel.access:admin')->group(function () {
            Route::post('/providers', [Controllers\DnsController::class, 'providerStore'])->name('providers.store');
            Route::put('/providers/{provider}', [Controllers\DnsController::class, 'providerUpdate'])->name('providers.update');
            Route::delete('/providers/{provider}', [Controllers\DnsController::class, 'providerDestroy'])->name('providers.destroy');
            Route::post('/providers/{provider}/toggle', [Controllers\DnsController::class, 'providerToggle'])->name('providers.toggle');
            Route::post('/providers/{provider}/test', [Controllers\DnsController::class, 'providerTest'])->name('providers.test');
            Route::post('/providers/{provider}/sync', [Controllers\DnsController::class, 'providerSync'])->name('providers.sync');
        });
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
    Route::get('/account/security', [Controllers\TwoFactorController::class, 'show'])->name('account.security');
    Route::post('/account/two-factor/setup', [Controllers\TwoFactorController::class, 'setup'])->middleware('throttle:10,1')->name('account.2fa.setup');
    Route::post('/account/two-factor/confirm', [Controllers\TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('account.2fa.confirm');
    Route::post('/account/two-factor/recovery-codes', [Controllers\TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:10,1')->name('account.2fa.recovery');
    Route::post('/account/two-factor/disable', [Controllers\TwoFactorController::class, 'disable'])->middleware('throttle:10,1')->name('account.2fa.disable');
    Route::post('/account/two-factor/forget-browser', [Controllers\TwoFactorController::class, 'forgetBrowser'])->name('account.2fa.forget');

    // Administrators only
    Route::middleware('panel.access:admin')->group(function () {
        Route::get('/terminal', [Controllers\TerminalController::class, 'index'])->name('terminal.index');
        Route::post('/terminal/token', [Controllers\TerminalController::class, 'token'])->name('terminal.token');
        Route::post('/terminal/exec', [Controllers\TerminalController::class, 'exec'])->name('terminal.exec');

        Route::get('/accounts', [Controllers\AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [Controllers\AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{user}', [Controllers\AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{user}', [Controllers\AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::post('/accounts/{user}/two-factor-reset', [Controllers\TwoFactorController::class, 'reset'])->name('accounts.2fa.reset');
        Route::post('/accounts/two-factor-policy', [Controllers\TwoFactorController::class, 'policy'])->name('accounts.2fa.policy');

        // Clients (hosting customers and the client sub-panel)
        Route::get('/clients', [Controllers\ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/list', [Controllers\ClientController::class, 'list'])->name('clients.list');
        Route::post('/clients', [Controllers\ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/storage', [Controllers\ClientController::class, 'storage'])->name('clients.storage');
        Route::post('/clients/usage', [Controllers\ClientController::class, 'refreshUsage'])->name('clients.usage');
        Route::get('/clients/logs', [Controllers\ClientController::class, 'logs'])->name('clients.logs');
        Route::post('/clients/settings', [Controllers\ClientController::class, 'saveSettings'])->name('clients.settings');
        Route::post('/clients/packages', [Controllers\ClientController::class, 'packageStore'])->name('clients.packages.store');
        Route::put('/clients/packages/{package}', [Controllers\ClientController::class, 'packageUpdate'])->name('clients.packages.update');
        Route::delete('/clients/packages/{package}', [Controllers\ClientController::class, 'packageDestroy'])->name('clients.packages.destroy');
        Route::put('/clients/{client}', [Controllers\ClientController::class, 'update'])->name('clients.update');
        Route::delete('/clients/{client}', [Controllers\ClientController::class, 'destroy'])->name('clients.destroy');
        Route::post('/clients/{client}/suspend', [Controllers\ClientController::class, 'suspend'])->name('clients.suspend');
        Route::post('/clients/{client}/unsuspend', [Controllers\ClientController::class, 'unsuspend'])->name('clients.unsuspend');
        Route::post('/clients/{client}/two-factor-reset', [Controllers\ClientController::class, 'resetTwoFactor'])->name('clients.2fa.reset');
        Route::get('/clients/{client}/resources', [Controllers\ClientController::class, 'resources'])->name('clients.resources');
        Route::post('/clients/{client}/resources', [Controllers\ClientController::class, 'assign'])->name('clients.assign');
        Route::post('/clients/{client}/login', [Controllers\Client\ClientAuthController::class, 'impersonate'])->name('clients.impersonate');

        Route::get('/home/updates', [Controllers\DashboardController::class, 'updates'])->name('home.updates');

        // API: keys, webhooks, call log and documentation
        Route::get('/api-access', [Controllers\ApiAccessController::class, 'index'])->name('api.index');
        Route::get('/api-access/keys', [Controllers\ApiAccessController::class, 'keys'])->name('api.keys');
        Route::post('/api-access/keys', [Controllers\ApiAccessController::class, 'keyStore'])->name('api.keys.store');
        Route::put('/api-access/keys/{key}', [Controllers\ApiAccessController::class, 'keyUpdate'])->name('api.keys.update');
        Route::delete('/api-access/keys/{key}', [Controllers\ApiAccessController::class, 'keyDestroy'])->name('api.keys.destroy');
        Route::post('/api-access/keys/{key}/toggle', [Controllers\ApiAccessController::class, 'keyToggle'])->name('api.keys.toggle');
        Route::post('/api-access/keys/{key}/rotate', [Controllers\ApiAccessController::class, 'keyRotate'])->name('api.keys.rotate');
        Route::get('/api-access/webhooks', [Controllers\ApiAccessController::class, 'webhooks'])->name('api.webhooks');
        Route::post('/api-access/webhooks', [Controllers\ApiAccessController::class, 'webhookStore'])->name('api.webhooks.store');
        Route::put('/api-access/webhooks/{webhook}', [Controllers\ApiAccessController::class, 'webhookUpdate'])->name('api.webhooks.update');
        Route::delete('/api-access/webhooks/{webhook}', [Controllers\ApiAccessController::class, 'webhookDestroy'])->name('api.webhooks.destroy');
        Route::post('/api-access/webhooks/{webhook}/test', [Controllers\ApiAccessController::class, 'webhookTest'])->name('api.webhooks.test');
        Route::post('/api-access/webhooks/{webhook}/secret', [Controllers\ApiAccessController::class, 'webhookSecret'])->name('api.webhooks.secret');
        Route::get('/api-access/webhooks/{webhook}/deliveries', [Controllers\ApiAccessController::class, 'webhookDeliveries'])->name('api.webhooks.deliveries');
        Route::get('/api-access/logs', [Controllers\ApiAccessController::class, 'logs'])->name('api.logs');
        Route::post('/api-access/logs/clear', [Controllers\ApiAccessController::class, 'clearLogs'])->name('api.logs.clear');

        // Backup: remote storages (rclone) and the transfers between them
        Route::get('/backup', [Controllers\BackupController::class, 'index'])->name('backup.index');
        Route::post('/backup/settings', [Controllers\BackupController::class, 'saveSettings'])->name('backup.settings');
        Route::post('/backup/install', [Controllers\BackupController::class, 'install'])->name('backup.install');
        Route::get('/backup/local', [Controllers\BackupController::class, 'localList'])->name('backup.local');
        Route::post('/backup/upload', [Controllers\BackupController::class, 'upload'])->name('backup.upload');
        Route::get('/backup/transfers', [Controllers\BackupController::class, 'transferList'])->name('backup.transfers');
        Route::get('/backup/transfers/{transfer}/log', [Controllers\BackupController::class, 'transferLog'])->name('backup.transfers.log');
        Route::post('/backup/transfers/{transfer}/retry', [Controllers\BackupController::class, 'transferRetry'])->name('backup.transfers.retry');
        Route::post('/backup/transfers/{transfer}/cancel', [Controllers\BackupController::class, 'transferCancel'])->name('backup.transfers.cancel');
        Route::delete('/backup/transfers/{transfer}', [Controllers\BackupController::class, 'transferDestroy'])->name('backup.transfers.destroy');
        Route::get('/backup/google/callback', [Controllers\BackupController::class, 'googleCallback'])->name('backup.google.callback');
        Route::post('/backup/google/start', [Controllers\BackupController::class, 'googleStart'])->name('backup.google.start');
        Route::get('/backup/storages', [Controllers\BackupController::class, 'storageList'])->name('backup.storages');
        Route::post('/backup/storages', [Controllers\BackupController::class, 'storageStore'])->name('backup.storages.store');
        Route::put('/backup/storages/{storage}', [Controllers\BackupController::class, 'storageUpdate'])->name('backup.storages.update');
        Route::delete('/backup/storages/{storage}', [Controllers\BackupController::class, 'storageDestroy'])->name('backup.storages.destroy');
        Route::post('/backup/storages/{storage}/toggle', [Controllers\BackupController::class, 'storageToggle'])->name('backup.storages.toggle');
        Route::post('/backup/storages/{storage}/test', [Controllers\BackupController::class, 'storageTest'])->name('backup.storages.test');
        Route::post('/backup/storages/{storage}/usage', [Controllers\BackupController::class, 'storageUsage'])->name('backup.storages.usage');
        Route::get('/backup/storages/{storage}/browse', [Controllers\BackupController::class, 'browse'])->name('backup.storages.browse');
        Route::get('/backup/storages/{storage}/download', [Controllers\BackupController::class, 'download'])->name('backup.storages.download');
        Route::post('/backup/storages/{storage}/fetch', [Controllers\BackupController::class, 'fetch'])->name('backup.storages.fetch');
        Route::post('/backup/storages/{storage}/delete-file', [Controllers\BackupController::class, 'deleteFile'])->name('backup.storages.delete-file');

        Route::get('/settings', [Controllers\SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/panel', [Controllers\SettingsController::class, 'panel'])->name('settings.panel');
        Route::post('/settings/access', [Controllers\SettingsController::class, 'access'])->name('settings.access');
        Route::post('/settings/security',[Controllers\SettingsController::class, 'security'])->name('settings.security');
        Route::post('/settings/ai', [Controllers\SettingsController::class, 'ai'])->name('settings.ai');
        Route::post('/settings/system', [Controllers\SettingsController::class, 'system'])->name('settings.system');
        Route::post('/settings/action', [Controllers\SettingsController::class, 'action'])->name('settings.action');
    });
});
