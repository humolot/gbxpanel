<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Website;

/**
 * Deploys a website from a Git repository (public, HTTPS token or SSH deploy key).
 * Secrets never go into task scripts: the token and the private key are files readable
 * by root only in the site's private directory.
 */
class GitDeployer
{
    public static function validRepository(string $url): bool
    {
        return (bool) preg_match('#^(https://[\w.\-]+(:\d+)?/[\w.\-~/]+?(\.git)?|git@[\w.\-]+:[\w.\-~/]+?(\.git)?|ssh://git@[\w.\-]+(:\d+)?/[\w.\-~/]+?(\.git)?)$#', $url);
    }

    public static function validBranch(string $branch): bool
    {
        return (bool) preg_match('#^(?!-)(?!.*\.\.)[\w.\-/]{1,120}$#', $branch);
    }

    public function keyPath(Website $site): string
    {
        return $site->privateDir().'/deploy_key';
    }

    public function tokenPath(Website $site): string
    {
        return $site->privateDir().'/git-token';
    }

    /** Public part of the per-site deploy key (created on first use). */
    public function publicKey(Website $site): string
    {
        if (Shell::simulating()) {
            return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG6simulatedDeployKeyForGbxPanel0000000 gbx-deploy@'.$site->domain;
        }
        $key = Shell::arg($this->keyPath($site));
        ApacheManager::ensurePrivateDir($site);
        Shell::run("[ -f {$key} ] || ssh-keygen -q -t ed25519 -N '' -C ".Shell::arg('gbx-deploy@'.$site->domain)." -f {$key}", 30)
            ->throw('Unable to create the deploy key');

        return trim(Shell::out("cat {$key}.pub", 10));
    }

    public function saveToken(Website $site, ?string $token): void
    {
        $path = $this->tokenPath($site);
        if ($token === null || $token === '') {
            Shell::run('rm -f '.Shell::arg($path));

            return;
        }
        ApacheManager::ensurePrivateDir($site);
        Shell::writeFile($path, $token, '0600', 'root:root')->throw('Unable to store the access token');
    }

    public function hasToken(Website $site): bool
    {
        return Shell::simulating() ? $site->setting('git.auth') === 'token' : Shell::fileExists($this->tokenPath($site));
    }

    /** Shell lines that authenticate git for this site. */
    protected function authEnv(Website $site, string $auth, ?string $tokenFile = null): string
    {
        $env = "export GIT_TERMINAL_PROMPT=0\n";
        if ($auth === 'ssh') {
            $env .= 'export GIT_SSH_COMMAND='.Shell::arg('ssh -i '.$this->keyPath($site).' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o BatchMode=yes')."\n";
        } elseif ($auth === 'token') {
            $file = Shell::arg($tokenFile ?? $this->tokenPath($site));
            $env .= "export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.extraHeader\n"
                ."export GIT_CONFIG_VALUE_0=\"Authorization: Basic \$(printf 'oauth2:%s' \"\$(cat {$file})\" | base64 -w0)\"\n";
        }

        return $env;
    }

    /**
     * Branches of a repository (git ls-remote), used by "Test connection".
     *
     * @return list<string>
     */
    public function branches(Website $site, string $repo, string $auth, ?string $token = null): array
    {
        if (! self::validRepository($repo)) {
            throw new \InvalidArgumentException('Invalid repository URL. Use https://host/owner/repo.git or git@host:owner/repo.git');
        }
        if (Shell::simulating()) {
            return ['main', 'develop', 'staging'];
        }
        if ($auth === 'ssh') {
            $this->publicKey($site);
        }

        $tmp = null;
        if ($auth === 'token' && $token) {
            $tmp = '/tmp/gbx-git-'.bin2hex(random_bytes(6));
            Shell::writeFile($tmp, $token, '0600', 'root:root');
        }
        $result = Shell::run($this->authEnv($site, $auth, $tmp)."timeout 30 git ls-remote --heads ".Shell::arg($repo).' 2>&1', 40);
        if ($tmp) {
            Shell::run('rm -f '.Shell::arg($tmp));
        }
        if ($result->failed()) {
            throw new \RuntimeException('Connection failed: '.trim(mb_substr($result->output.$result->error, 0, 400)));
        }

        preg_match_all('#refs/heads/(\S+)#', $result->output, $m);

        return $m[1];
    }

    public function deploy(Website $site): Task
    {
        $git = (array) $site->setting('git', []);
        $repo = (string) ($git['repo'] ?? '');
        $branch = (string) ($git['branch'] ?? 'main');
        if (! self::validRepository($repo) || ! self::validBranch($branch)) {
            throw new \InvalidArgumentException('Configure the repository and branch first.');
        }

        $root = Shell::arg($site->root_path);
        $user = Shell::arg(config('gbx.web_user').':'.config('gbx.web_user'));
        $safe = '-c safe.directory='.Shell::arg($site->root_path);

        $script = "set -e\n".$this->authEnv($site, (string) ($git['auth'] ?? 'public'))
            ."command -v git >/dev/null || { export DEBIAN_FRONTEND=noninteractive; apt-get update -y >/dev/null; apt-get install -y git >/dev/null; }\n"
            ."mkdir -p {$root}\n"
            ."if [ -d {$root}/.git ]; then\n"
            ."    echo \"Updating {$site->root_path} from {$branch}\"\n"
            ."    git {$safe} -C {$root} remote set-url origin ".Shell::arg($repo)."\n"
            ."    git {$safe} -C {$root} fetch --depth 1 origin ".Shell::arg($branch)."\n"
            ."    git {$safe} -C {$root} reset --hard FETCH_HEAD\n"
            ."else\n"
            ."    echo \"Cloning ".$repo." ({$branch}) into {$site->root_path}\"\n"
            ."    TMP=\$(mktemp -d)\n"
            ."    git clone --depth 1 --branch ".Shell::arg($branch).' '.Shell::arg($repo)." \"\$TMP/src\"\n"
            ."    grep -qs 'Powered by GBX Panel' {$root}/index.html && rm -f {$root}/index.html || true\n"
            ."    cp -a \"\$TMP/src/.\" {$root}/\n"
            ."    rm -rf \"\$TMP\"\n"
            ."fi\n"
            ."chown -R {$user} {$root}\n"
            ."echo \"Commit: \$(git {$safe} -C {$root} log -1 --format='%h %s (%an, %ar)')\"\n";

        $hook = trim((string) ($git['script'] ?? ''));
        if ($hook !== '') {
            $file = $site->privateDir().'/deploy.sh';
            ApacheManager::ensurePrivateDir($site);
            Shell::writeFile($file, "#!/bin/bash\nset -e\n".str_replace("\r\n", "\n", $hook)."\n", '0750', 'root:'.config('gbx.web_user'));
            $script .= "echo '==> Running deploy script as ".config('gbx.web_user')."'\n"
                .'cd '.$root.' && runuser -u '.Shell::arg(config('gbx.web_user')).' -- env HOME='.$root.' COMPOSER_HOME=/tmp/composer bash '.Shell::arg($file)."\n";
        }
        $script .= "echo 'Deployment finished.'";

        return TaskRunner::dispatch("Deploy {$site->domain} from Git", $script, 'deploy', ['website_id' => $site->id, 'on_success' => 'git_deployed']);
    }
}
