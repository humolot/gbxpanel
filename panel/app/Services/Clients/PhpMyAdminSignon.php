<?php

namespace App\Services\Clients;

use App\Models\MysqlDatabase;
use App\Services\Shell;

/**
 * phpMyAdmin for clients without typing the database password: phpMyAdmin server 2 uses the
 * "signon" authentication and reads the credentials of the client's database user from a PHP
 * session written by the panel (both run in the gbxpanel PHP-FPM pool, which shares the session folder).
 * The database user only has privileges on its own database.
 */
class PhpMyAdminSignon
{
    public const SESSION = 'GbxPmaSignon';

    public const SERVER = 2;

    protected const MARKER = 'GBX Panel client sign-on';

    public static function dir(): string
    {
        return rtrim((string) config('gbx.root'), '/').'/phpmyadmin';
    }

    public static function installed(): bool
    {
        return Shell::simulating() || Shell::test('test -f '.Shell::arg(self::dir().'/index.php'));
    }

    /** Configuration appended once to phpMyAdmin's config.inc.php. */
    public static function configBlock(): string
    {
        return "\n/* ".self::MARKER." (server ".self::SERVER.") */\n"
            ."\$i = ".self::SERVER.";\n"
            ."\$cfg['Servers'][\$i]['verbose'] = 'Client panel';\n"
            ."\$cfg['Servers'][\$i]['host'] = 'localhost';\n"
            ."\$cfg['Servers'][\$i]['auth_type'] = 'signon';\n"
            ."\$cfg['Servers'][\$i]['SignonSession'] = '".self::SESSION."';\n"
            ."\$cfg['Servers'][\$i]['SignonCookieParams'] = ['lifetime' => 0, 'path' => '/phpmyadmin/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'];\n"
            ."\$cfg['Servers'][\$i]['SignonURL'] = '/client/databases';\n"
            ."\$cfg['Servers'][\$i]['LogoutURL'] = '/client/databases';\n"
            ."\$cfg['Servers'][\$i]['AllowRoot'] = false;\n";
    }

    public function ensureConfig(): void
    {
        if (Shell::simulating()) {
            return;
        }
        $config = Shell::arg(self::dir().'/config.inc.php');
        Shell::run("[ -f {$config} ] && ! grep -q ".Shell::arg(self::MARKER)." {$config} && cat >> {$config}; true", 20, self::configBlock());
    }

    /** Write the sign-on session and return the phpMyAdmin URL to open. */
    public function open(MysqlDatabase $database, bool $secure): string
    {
        $this->ensureConfig();
        $url = '/phpmyadmin/index.php?'.http_build_query(['server' => self::SERVER, 'db' => $database->name]);
        if (Shell::simulating() || app()->runningUnitTests()) {
            return $url;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_set_cookie_params(['lifetime' => 0, 'path' => '/phpmyadmin/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_name(self::SESSION);
        @session_start();
        session_regenerate_id(true);
        $_SESSION['PMA_single_signon_user'] = $database->username;
        $_SESSION['PMA_single_signon_password'] = (string) $database->password;
        $_SESSION['PMA_single_signon_host'] = 'localhost';
        $_SESSION['PMA_single_signon_cfgupdate'] = ['verbose' => 'Client panel'];
        session_write_close();

        return $url;
    }
}
