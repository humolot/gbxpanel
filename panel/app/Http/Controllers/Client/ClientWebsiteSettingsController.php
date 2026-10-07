<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WebsiteSettingsController;
use App\Models\Website;
use App\Services\Clients\ClientContext;
use App\Services\GitDeployer;
use App\Services\SoftwareManager;
use App\Services\SslManager;
use Illuminate\Http\Request;

/**
 * Website settings inside the client sub-panel.
 *
 * The client reaches the same screens as the administrator, but only for its own websites and
 * only for the sections that stay inside the website: moving the document root, the vhost file,
 * reverse proxies, Git and Composer remain with the administrator.
 */
class ClientWebsiteSettingsController extends WebsiteSettingsController
{
    /** Sections a client may open, with the actions allowed in each. */
    public const ALLOWED = [
        'domains' => ['add', 'remove'],
        'directory' => ['run_path', 'open_basedir', 'access_log'],
        'access' => ['save', 'delete'],
        'rewrite' => ['save'],
        'index' => ['save'],
        'redirects' => ['save', 'delete', 'toggle', 'not_found'],
        'hotlink' => ['save'],
        'maintenance' => ['save'],
    ];

    /** Sections whose data can be read (running directories, current .htaccess). */
    public const READABLE = ['subdirs', 'rewrite'];

    protected function owned(Website $website): Website
    {
        abort_unless($website->client_id && $website->client_id === ClientContext::id(), 404);

        return $website;
    }

    public function manage(Website $website, SoftwareManager $software, SslManager $ssl, GitDeployer $git)
    {
        $this->owned($website);

        $html = view('websites.manage', [
            'site' => $website,
            'only' => array_keys(self::ALLOWED),
            'url' => fn (string $section) => route('client.websites.manage.update', [$website, $section]),
            'lockRoot' => true,
            'plainEditors' => true,
            'readOnly' => false,
            'phpVersions' => $software->phpVersions(),
            'certificate' => $ssl->certificateInfo($website),
            'sslEmail' => null,
            'dnsZone' => null,
            'templates' => config('rewrite'),
            'openBasedir' => (bool) $website->setting('open_basedir', false),
        ])->render();

        return $this->ok('ok', ['html' => $html, 'title' => $website->domain]);
    }

    public function data(Request $request, Website $website, string $section, GitDeployer $git)
    {
        $this->owned($website);
        abort_unless(in_array($section, self::READABLE, true), 404);

        return parent::data($request, $website, $section, $git);
    }

    public function update(Request $request, Website $website, string $section, GitDeployer $git)
    {
        $this->owned($website);
        $action = (string) $request->input('action', 'save');

        if (! isset(self::ALLOWED[$section])) {
            return $this->fail('This setting is managed by the administrator.', 404);
        }
        if (! in_array($action, self::ALLOWED[$section], true)) {
            return $this->fail('This action is managed by the administrator.', 403);
        }
        // a wildcard alias would catch domains of other customers that point at this server
        if ($section === 'domains' && $action === 'add' && str_contains((string) $request->input('domains'), '*')) {
            return $this->fail('Wildcard domains (*.example.com) can only be added by the administrator.');
        }

        return parent::update($request, $website, $section, $git);
    }
}
