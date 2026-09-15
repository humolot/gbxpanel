<?php

namespace App\Services\Databases;

use App\Services\MysqlManager;

/** Engines shown as tabs on the Databases page. */
class Engines
{
    /** Engines that hold named databases (tracked in the databases table). */
    public const DATABASE_ENGINES = [
        'mysql' => MysqlManager::class,
        'pgsql' => PostgresEngine::class,
        'mongodb' => MongoEngine::class,
        'sqlserver' => SqlServerEngine::class,
    ];

    /** Tab order: key => [label, icon]. */
    public const TABS = [
        'mysql' => ['MySQL', 'bi-database'],
        'pgsql' => ['PostgreSQL', 'bi-database-fill'],
        'mongodb' => ['MongoDB', 'bi-diagram-2'],
        'redis' => ['Redis', 'bi-lightning-charge'],
        'sqlserver' => ['SQL Server', 'bi-server'],
        'qdrant' => ['Qdrant', 'bi-bounding-box-circles'],
    ];

    public const SERVER_ENGINES = ['mysql', 'pgsql', 'mongodb', 'sqlserver', 'redis', 'qdrant'];

    public static function get(string $key): DatabaseEngine
    {
        $class = self::DATABASE_ENGINES[$key] ?? throw new \InvalidArgumentException('Unknown database engine.');

        return app($class);
    }

    public static function isDatabaseEngine(string $key): bool
    {
        return isset(self::DATABASE_ENGINES[$key]);
    }
}
