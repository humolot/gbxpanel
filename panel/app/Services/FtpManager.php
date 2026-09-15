<?php

namespace App\Services;

/**
 * Pure-FTPd virtual users (PureDB). Every FTP user maps to the web user so
 * uploaded files keep the correct ownership for Apache/PHP.
 */
class FtpManager
{
    protected string $passwd = '/etc/pure-ftpd/pureftpd.passwd';

    protected string $pdb = '/etc/pure-ftpd/pureftpd.pdb';

    public static function validUsername(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $name);
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::fileExists('/usr/sbin/pure-ftpd');
    }

    public function create(string $username, string $password, string $path): ShellResult
    {
        $this->guard($username);
        $user = config('gbx.web_user');
        $cmd = 'mkdir -p '.Shell::arg($path).' && chown '.Shell::arg($user.':'.$user).' '.Shell::arg($path)
            .' && pure-pw useradd '.Shell::arg($username).' -u '.Shell::arg($user).' -g '.Shell::arg($user)
            .' -d '.Shell::arg($path).' -f '.Shell::arg($this->passwd).' -m';

        // pure-pw reads the password twice from stdin
        return Shell::run($cmd, 30, $password."\n".$password."\n");
    }

    public function changePassword(string $username, string $password): ShellResult
    {
        $this->guard($username);

        return Shell::run('pure-pw passwd '.Shell::arg($username).' -f '.Shell::arg($this->passwd).' -m', 30, $password."\n".$password."\n");
    }

    public function changePath(string $username, string $path): ShellResult
    {
        $this->guard($username);

        return Shell::run('pure-pw usermod '.Shell::arg($username).' -d '.Shell::arg($path).' -f '.Shell::arg($this->passwd).' -m', 30);
    }

    /** Disabling is done by restricting the allowed client IP to an unroutable address. */
    public function setActive(string $username, bool $active): ShellResult
    {
        $this->guard($username);
        $flag = $active ? "-r ''" : '-r 127.0.0.255/32';

        return Shell::run('pure-pw usermod '.Shell::arg($username).' '.$flag.' -f '.Shell::arg($this->passwd).' -m', 30);
    }

    public function delete(string $username): ShellResult
    {
        $this->guard($username);

        return Shell::run('pure-pw userdel '.Shell::arg($username).' -f '.Shell::arg($this->passwd).' -m', 30);
    }

    public function status(): array
    {
        return app(ServiceManager::class)->status('pure-ftpd');
    }

    protected function guard(string $username): void
    {
        if (! self::validUsername($username)) {
            throw new \InvalidArgumentException('Invalid FTP username.');
        }
    }
}
