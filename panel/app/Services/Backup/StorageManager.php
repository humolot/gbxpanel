<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;
use App\Models\Setting;
use App\Services\Shell;
use App\Services\ShellResult;

/**
 * Remote backup destinations. Every provider is reached through rclone: the panel renders a
 * root-only rclone configuration for each operation (secrets never appear on command lines),
 * and reads back OAuth tokens that rclone refreshed while it ran.
 */
class StorageManager
{
    public const CONFIG_DIR = '/root/.gbx-rclone';

    protected const S3_KEYS = [
        'access_key_id' => ['label' => 'Access Key', 'type' => 'text', 'required' => true],
        'secret_access_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true],
    ];

    protected const BUCKET = ['bucket' => ['label' => 'Bucket', 'type' => 'text', 'required' => true, 'placeholder' => 'Existing bucket name']];

    protected const OAUTH_APP = [
        'client_id' => ['label' => 'Client ID', 'type' => 'text', 'placeholder' => 'Optional: your own OAuth app'],
        'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'placeholder' => 'Optional: your own OAuth app'],
    ];

    public const GROUPS = [
        'object' => 'Object storage',
        'cloud' => 'Cloud drives',
        'server' => 'Remote servers',
    ];

    /**
     * Supported destinations. "backend" is the rclone backend, "provider" and "endpoint" configure
     * S3 compatible services, "bucket" types keep backups under bucket/folder, "split" types have no
     * resumable uploads: large archives are sent in parts so a failed transfer only repeats one part.
     */
    public const TYPES = [
        'aws' => [
            'name' => 'Amazon S3', 'group' => 'object', 'backend' => 's3', 'provider' => 'AWS', 'bucket' => true,
            'icon' => 'bi-amazon', 'color' => '#ff9900',
            'fields' => self::S3_KEYS + [
                'region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'us-east-1'],
            ] + self::BUCKET + [
                'storage_class' => ['label' => 'Storage class', 'type' => 'select', 'options' => ['' => 'Standard', 'STANDARD_IA' => 'Standard-IA', 'ONEZONE_IA' => 'One Zone-IA', 'INTELLIGENT_TIERING' => 'Intelligent-Tiering', 'GLACIER_IR' => 'Glacier Instant Retrieval']],
            ],
            'docs' => 'https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html',
            'notes' => ['Create an IAM user with s3:ListBucket, s3:GetObject, s3:PutObject and s3:DeleteObject on the bucket and use its access keys.'],
        ],
        'digitalocean' => [
            'name' => 'DigitalOcean Spaces', 'group' => 'object', 'backend' => 's3', 'provider' => 'DigitalOcean', 'bucket' => true,
            'endpoint' => '{region}.digitaloceanspaces.com', 'icon' => 'bi-droplet-fill', 'color' => '#0080ff',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'nyc3, ams3, sfo3, sgp1, fra1']] + self::BUCKET,
            'docs' => 'https://docs.digitalocean.com/products/spaces/how-to/manage-access/',
            'notes' => ['Create a Spaces access key limited to the bucket (Read/Write/Delete). The region is the first part of the bucket endpoint.'],
        ],
        'linode' => [
            'name' => 'Linode Object Storage', 'group' => 'object', 'backend' => 's3', 'provider' => 'Linode', 'bucket' => true,
            'endpoint' => '{region}.linodeobjects.com', 'icon' => 'bi-diagram-3-fill', 'color' => '#00a95c',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'us-east-1, us-iad-1, eu-central-1, br-gru-1']] + self::BUCKET,
            'docs' => 'https://techdocs.akamai.com/cloud-computing/docs/manage-access-keys',
            'notes' => ['Create an Object Storage access key with read/write access to the bucket. The region is the part before .linodeobjects.com in the bucket URL.'],
        ],
        'wasabi' => [
            'name' => 'Wasabi', 'group' => 'object', 'backend' => 's3', 'provider' => 'Wasabi', 'bucket' => true,
            'endpoint' => 's3.{region}.wasabisys.com', 'icon' => 'bi-hdd-stack-fill', 'color' => '#00c853',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'us-east-1, eu-central-1, sa-east-1']] + self::BUCKET,
            'docs' => 'https://docs.wasabi.com/docs/creating-a-user-account-and-access-key',
            'notes' => ['Wasabi bills a minimum storage period of 90 days: deleting older backups earlier is still charged.'],
        ],
        'b2' => [
            'name' => 'Backblaze B2', 'group' => 'object', 'backend' => 'b2', 'bucket' => true,
            'icon' => 'bi-fire', 'color' => '#e21e29',
            'fields' => [
                'account' => ['label' => 'Key ID', 'type' => 'text', 'required' => true],
                'key' => ['label' => 'Application Key', 'type' => 'password', 'required' => true],
            ] + self::BUCKET,
            'docs' => 'https://www.backblaze.com/docs/cloud-storage-create-and-manage-app-keys',
            'notes' => ['Create an application key restricted to the bucket with read and write access. By default B2 keeps old versions of deleted files; set the bucket lifecycle to keep only the last version.'],
        ],
        'r2' => [
            'name' => 'Cloudflare R2', 'group' => 'object', 'backend' => 's3', 'provider' => 'Cloudflare', 'bucket' => true,
            'endpoint' => 'https://{account_id}.r2.cloudflarestorage.com', 'icon' => 'bi-cloud-fill', 'color' => '#f38020',
            'fields' => ['account_id' => ['label' => 'Account ID', 'type' => 'text', 'required' => true, 'placeholder' => '32 characters, shown in R2 > Overview']] + self::S3_KEYS + self::BUCKET,
            'docs' => 'https://developers.cloudflare.com/r2/api/tokens/',
            'notes' => ['Create an R2 API token with Object Read & Write permission for the bucket and use its S3 Access Key ID and Secret Access Key.'],
        ],
        'vultr' => [
            'name' => 'Vultr Object Storage', 'group' => 'object', 'backend' => 's3', 'provider' => 'Other', 'bucket' => true,
            'endpoint' => '{region}.vultrobjects.com', 'icon' => 'bi-box-seam', 'color' => '#007bfc',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'ewr1, sjc1, ams1, blr1, del1']] + self::BUCKET,
            'docs' => 'https://docs.vultr.com/products/cloud-storage/object-storage',
            'notes' => ['Use the S3 credentials of the Object Storage subscription. The region is the first part of the hostname.'],
        ],
        'hetzner' => [
            'name' => 'Hetzner Object Storage', 'group' => 'object', 'backend' => 's3', 'provider' => 'Other', 'bucket' => true,
            'endpoint' => '{region}.your-objectstorage.com', 'icon' => 'bi-hdd-network-fill', 'color' => '#d50c2d',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Location', 'type' => 'text', 'required' => true, 'placeholder' => 'fsn1, nbg1, hel1']] + self::BUCKET,
            'docs' => 'https://docs.hetzner.com/storage/object-storage/getting-started/generating-s3-keys/',
            'notes' => ['Generate S3 credentials in the Cloud Console project of the bucket.'],
        ],
        'contabo' => [
            'name' => 'Contabo Object Storage', 'group' => 'object', 'backend' => 's3', 'provider' => 'Other', 'bucket' => true,
            'endpoint' => '{region}.contabostorage.com', 'icon' => 'bi-archive-fill', 'color' => '#1e88e5',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'eu2, usc1, sin1']] + self::BUCKET,
            'docs' => 'https://docs.contabo.com/docs/products/Object-Storage/',
            'notes' => ['The S3 credentials are in Customer Control Panel > Account > Security & Access.'],
        ],
        'scaleway' => [
            'name' => 'Scaleway Object Storage', 'group' => 'object', 'backend' => 's3', 'provider' => 'Scaleway', 'bucket' => true,
            'endpoint' => 's3.{region}.scw.cloud', 'icon' => 'bi-grid-3x3-gap-fill', 'color' => '#4f0599',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'fr-par, nl-ams, pl-waw']] + self::BUCKET,
            'docs' => 'https://www.scaleway.com/en/docs/iam/how-to/create-api-keys/',
            'notes' => ['Create an API key for Object Storage in the project of the bucket.'],
        ],
        'ovh' => [
            'name' => 'OVHcloud Object Storage', 'group' => 'object', 'backend' => 's3', 'provider' => 'Other', 'bucket' => true,
            'endpoint' => 's3.{region}.io.cloud.ovh.net', 'icon' => 'bi-cloud-haze2-fill', 'color' => '#000e9c',
            'fields' => self::S3_KEYS + ['region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'placeholder' => 'gra, sbg, bhs, de, uk, waw']] + self::BUCKET,
            'docs' => 'https://help.ovhcloud.com/csm/en-public-cloud-storage-s3-getting-started-object-storage',
            'notes' => ['Create an S3 user in Public Cloud > Object Storage > Users and use its access key.'],
        ],
        'gcs' => [
            'name' => 'Google Cloud Storage', 'group' => 'object', 'backend' => 'google cloud storage', 'bucket' => true,
            'icon' => 'bi-google', 'color' => '#4285f4',
            'fields' => [
                'service_account_credentials' => ['label' => 'Service account JSON', 'type' => 'secret_text', 'required' => true, 'placeholder' => 'Paste the JSON key of the service account'],
            ] + self::BUCKET,
            'docs' => 'https://cloud.google.com/iam/docs/keys-create-delete',
            'notes' => ['Create a service account with the Storage Object Admin role on the bucket and create a JSON key for it.'],
        ],
        'azureblob' => [
            'name' => 'Azure Blob Storage', 'group' => 'object', 'backend' => 'azureblob', 'bucket' => true,
            'icon' => 'bi-microsoft', 'color' => '#0078d4',
            'fields' => [
                'account' => ['label' => 'Storage account', 'type' => 'text', 'required' => true],
                'key' => ['label' => 'Access key', 'type' => 'password', 'required' => true],
                'bucket' => ['label' => 'Container', 'type' => 'text', 'required' => true],
            ],
            'docs' => 'https://learn.microsoft.com/en-us/azure/storage/common/storage-account-keys-manage',
            'notes' => ['The access key is in Storage account > Security + networking > Access keys.'],
        ],
        's3' => [
            'name' => 'Other S3 compatible', 'group' => 'object', 'backend' => 's3', 'provider' => 'Other', 'bucket' => true,
            'icon' => 'bi-bucket-fill', 'color' => '#c72e49',
            'fields' => [
                'endpoint' => ['label' => 'Endpoint', 'type' => 'text', 'required' => true, 'placeholder' => 'https://s3.example.com (MinIO, Ceph, Magalu Cloud, IDrive e2...)'],
                'region' => ['label' => 'Region', 'type' => 'text', 'placeholder' => 'Optional, for example us-east-1'],
            ] + self::S3_KEYS + self::BUCKET + [
                'virtual_host' => ['label' => 'Virtual-hosted URLs', 'type' => 'checkbox', 'help' => 'bucket.endpoint instead of endpoint/bucket'],
            ],
            'docs' => 'https://rclone.org/s3/',
            'notes' => ['Any service that speaks the S3 API. MinIO and most self-hosted services use path-style URLs (the default).'],
        ],

        'drive' => [
            'name' => 'Google Drive', 'group' => 'cloud', 'backend' => 'drive', 'oauth' => 'google', 'authorize' => 'drive',
            'icon' => 'bi-google', 'color' => '#1fa463', 'chunk' => '--drive-chunk-size 64M',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'placeholder' => 'OAuth client of your Google Cloud project'],
                'client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'scope' => ['label' => 'Access', 'type' => 'select', 'options' => ['drive.file' => 'Only files created by the panel (recommended)', 'drive' => 'Full access to the Drive']],
                'root_folder_id' => ['label' => 'Root folder ID', 'type' => 'text', 'placeholder' => 'Optional: ID of an existing folder (needs full access)'],
                'team_drive' => ['label' => 'Shared drive ID', 'type' => 'text', 'placeholder' => 'Optional: store in a shared drive'],
                'token' => ['label' => 'Token', 'type' => 'token'],
            ],
            'docs' => 'https://rclone.org/drive/#making-your-own-client-id',
            'notes' => [
                'Connect with Google: create an OAuth client (type Web application) in Google Cloud Console > APIs & Services > Credentials, enable the Google Drive API and add the redirect URI shown below. Google only accepts HTTPS redirect URIs, so the panel must be opened through a domain with SSL.',
                'Paste code: run the rclone command shown below on a computer with a browser, sign in to Google and paste the result. This works when the panel is opened by IP.',
                'Google Drive accepts up to 750 GB of uploads per account per day.',
            ],
        ],
        'dropbox' => [
            'name' => 'Dropbox', 'group' => 'cloud', 'backend' => 'dropbox', 'authorize' => 'dropbox',
            'icon' => 'bi-dropbox', 'color' => '#0061fe', 'chunk' => '--dropbox-chunk-size 48M',
            'fields' => self::OAUTH_APP + ['token' => ['label' => 'Token', 'type' => 'token']],
            'docs' => 'https://rclone.org/dropbox/',
            'notes' => ['Run the rclone command shown below on a computer with a browser, allow access and paste the result.'],
        ],
        'onedrive' => [
            'name' => 'Microsoft OneDrive', 'group' => 'cloud', 'backend' => 'onedrive', 'authorize' => 'onedrive',
            'icon' => 'bi-microsoft', 'color' => '#0364b8', 'chunk' => '--onedrive-chunk-size 10M',
            'fields' => self::OAUTH_APP + [
                'token' => ['label' => 'Token', 'type' => 'token'],
                'drive_type' => ['label' => 'Account type', 'type' => 'select', 'options' => ['personal' => 'OneDrive Personal', 'business' => 'OneDrive for Business', 'documentLibrary' => 'SharePoint library']],
                'drive_id' => ['label' => 'Drive ID', 'type' => 'text', 'placeholder' => 'Empty: detected from the token'],
            ],
            'docs' => 'https://rclone.org/onedrive/',
            'notes' => ['Run the rclone command shown below, sign in with the Microsoft account and paste the result right away: the drive is detected with the token.'],
        ],
        'pcloud' => [
            'name' => 'pCloud', 'group' => 'cloud', 'backend' => 'pcloud', 'authorize' => 'pcloud', 'split' => true,
            'icon' => 'bi-cloud-arrow-up-fill', 'color' => '#17bed0',
            'fields' => self::OAUTH_APP + [
                'token' => ['label' => 'Token', 'type' => 'token'],
                'hostname' => ['label' => 'Data region', 'type' => 'select', 'options' => ['api.pcloud.com' => 'United States', 'eapi.pcloud.com' => 'Europe']],
            ],
            'docs' => 'https://rclone.org/pcloud/',
            'notes' => ['Run the rclone command shown below and paste the result. Choose the region where the pCloud account was created.'],
        ],
        'box' => [
            'name' => 'Box', 'group' => 'cloud', 'backend' => 'box', 'authorize' => 'box',
            'icon' => 'bi-box2-fill', 'color' => '#0061d5',
            'fields' => self::OAUTH_APP + ['token' => ['label' => 'Token', 'type' => 'token']],
            'docs' => 'https://rclone.org/box/',
            'notes' => ['Run the rclone command shown below and paste the result.'],
        ],

        'sftp' => [
            'name' => 'SFTP (SSH)', 'group' => 'server', 'backend' => 'sftp', 'split' => true, 'absolute' => true,
            'icon' => 'bi-terminal-fill', 'color' => '#5c6b7a',
            'fields' => [
                'host' => ['label' => 'Host', 'type' => 'text', 'required' => true],
                'port' => ['label' => 'Port', 'type' => 'text', 'default' => '22'],
                'user' => ['label' => 'User', 'type' => 'text', 'required' => true],
                'pass' => ['label' => 'Password', 'type' => 'password', 'placeholder' => 'Empty when a private key is used'],
                'key_pem' => ['label' => 'Private key', 'type' => 'secret_text', 'placeholder' => 'Optional: -----BEGIN OPENSSH PRIVATE KEY----- (without passphrase)'],
            ],
            'docs' => 'https://rclone.org/sftp/',
            'notes' => ['The folder is relative to the home directory of the user unless it starts with /. Archives larger than the part size are sent in parts.'],
        ],
        'ftp' => [
            'name' => 'FTP / FTPS', 'group' => 'server', 'backend' => 'ftp', 'split' => true, 'absolute' => true,
            'icon' => 'bi-folder-symlink-fill', 'color' => '#8a6d3b',
            'fields' => [
                'host' => ['label' => 'Host', 'type' => 'text', 'required' => true],
                'port' => ['label' => 'Port', 'type' => 'text', 'default' => '21'],
                'user' => ['label' => 'User', 'type' => 'text', 'required' => true],
                'pass' => ['label' => 'Password', 'type' => 'password', 'required' => true],
                'tls' => ['label' => 'Encryption', 'type' => 'select', 'options' => ['explicit' => 'Explicit FTPS (recommended)', 'implicit' => 'Implicit FTPS (port 990)', 'none' => 'None (plain FTP)']],
                'no_check_certificate' => ['label' => 'Accept self-signed certificates', 'type' => 'checkbox'],
            ],
            'docs' => 'https://rclone.org/ftp/',
            'notes' => ['Plain FTP sends the password unencrypted. FTP has no resumable uploads: archives larger than the part size are sent in parts so a failure only repeats one part.'],
        ],
        'webdav' => [
            'name' => 'WebDAV / Nextcloud', 'group' => 'server', 'backend' => 'webdav', 'split' => true,
            'icon' => 'bi-hdd-rack-fill', 'color' => '#0082c9',
            'fields' => [
                'url' => ['label' => 'URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://cloud.example.com/remote.php/dav/files/USER'],
                'vendor' => ['label' => 'Server', 'type' => 'select', 'options' => ['nextcloud' => 'Nextcloud', 'owncloud' => 'ownCloud', 'other' => 'Other WebDAV']],
                'user' => ['label' => 'User', 'type' => 'text', 'required' => true],
                'pass' => ['label' => 'Password', 'type' => 'password', 'required' => true, 'placeholder' => 'An app password is recommended'],
            ],
            'docs' => 'https://rclone.org/webdav/',
            'notes' => ['For Nextcloud create an app password in Personal settings > Security.'],
        ],
    ];

    /** Fields whose value rclone expects obscured in its configuration. */
    protected const OBSCURED = ['pass'];

    public static function secretTypes(): array
    {
        return ['password', 'token', 'secret_text'];
    }

    /* ================================================================ rclone */

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('rclone');
    }

    public function version(): ?string
    {
        if (Shell::simulating()) {
            return 'v1.71.0 (simulated)';
        }
        preg_match('/rclone (v[\d.]+)/', Shell::out('rclone version 2>/dev/null | head -1', 15), $m);

        return $m[1] ?? null;
    }

    /** Installs the official rclone build, verified against the SHA256SUMS of the release. */
    public static function installScript(): string
    {
        return <<<'BASH'
set -e
case "$(uname -m)" in
  x86_64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  armv7l) ARCH=arm-v7 ;;
  *) echo "Unsupported architecture $(uname -m)"; exit 1 ;;
esac
command -v unzip >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y unzip
VERSION=$(curl -fsSL https://downloads.rclone.org/version.txt | awk '{print $2}')
case "$VERSION" in v[0-9]*) ;; *) echo "Could not read the current rclone version"; exit 1 ;; esac
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
FILE="rclone-${VERSION}-linux-${ARCH}.zip"
echo "Downloading rclone ${VERSION} (${ARCH})"
curl -fsSL -o "$TMP/$FILE" "https://downloads.rclone.org/${VERSION}/${FILE}"
curl -fsSL -o "$TMP/SHA256SUMS" "https://downloads.rclone.org/${VERSION}/SHA256SUMS"
(cd "$TMP" && grep " ${FILE}\$" SHA256SUMS | sha256sum -c -)
unzip -q -o "$TMP/$FILE" -d "$TMP"
install -m 0755 "$TMP/rclone-${VERSION}-linux-${ARCH}/rclone" /usr/bin/rclone
mkdir -p /root/.gbx-rclone && chmod 700 /root/.gbx-rclone
rclone version | head -1
BASH;
    }

    /* ============================================================ addressing */

    public static function remoteName(BackupStorage $storage): string
    {
        return 'gbx'.(int) $storage->id;
    }

    /** Clean relative path inside a storage (no parent references, no control characters). */
    public static function cleanPath(?string $path): string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new \InvalidArgumentException('Invalid path.');
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \InvalidArgumentException('Parent folders (..) are not allowed.');
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** rclone path of the storage root: remote:bucket/folder or remote:folder. */
    public function root(BackupStorage $storage): string
    {
        $def = $storage->definition();
        $folder = trim((string) $storage->folder);
        $absolute = ! empty($def['absolute']) && str_starts_with($folder, '/');
        $folder = self::cleanPath($folder);
        $base = ! empty($def['bucket']) ? trim((string) $storage->credential('bucket'), '/').($folder !== '' ? '/'.$folder : '') : ($absolute ? '/'.$folder : $folder);

        return self::remoteName($storage).':'.$base;
    }

    public function path(BackupStorage $storage, string $relative = ''): string
    {
        $root = $this->root($storage);
        $relative = self::cleanPath($relative);
        if ($relative === '') {
            return $root;
        }

        return str_ends_with($root, ':') || str_ends_with($root, '/') ? $root.$relative : $root.'/'.$relative;
    }

    /* ======================================================== configuration */

    protected static function oneLine(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    /** Endpoint of S3 compatible providers built from the region or the account ID. */
    public static function endpoint(BackupStorage $storage): string
    {
        $def = $storage->definition();
        if (empty($def['endpoint'])) {
            return self::oneLine((string) $storage->credential('endpoint'));
        }

        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => self::oneLine((string) $storage->credential($m[1])), $def['endpoint']);
    }

    /** rclone configuration section of a storage. */
    public function config(BackupStorage $storage): string
    {
        $def = $storage->definition();
        $c = fn (string $key, string $default = '') => self::oneLine((string) $storage->credential($key, $default));
        $values = ['type' => $def['backend']];

        switch ($def['backend']) {
            case 's3':
                $values += ['provider' => $def['provider'], 'access_key_id' => $c('access_key_id'), 'secret_access_key' => $c('secret_access_key')];
                $values['endpoint'] = self::endpoint($storage);
                $values['region'] = match ($storage->type) {
                    'r2' => 'auto',
                    'digitalocean', 'linode' => '',
                    default => $c('region'),
                };
                $values['acl'] = 'private';
                $values['no_check_bucket'] = 'true';
                if ($storage->type === 'aws' && $c('storage_class') !== '') {
                    $values['storage_class'] = $c('storage_class');
                }
                if ($storage->type === 's3') {
                    $values['force_path_style'] = $c('virtual_host') ? 'false' : 'true';
                }
                break;
            case 'b2':
                $values += ['account' => $c('account'), 'key' => $c('key')];
                break;
            case 'google cloud storage':
                $json = json_decode((string) $storage->credential('service_account_credentials'), true);
                $values += ['service_account_credentials' => is_array($json) ? json_encode($json, JSON_UNESCAPED_SLASHES) : '', 'bucket_policy_only' => 'true'];
                break;
            case 'azureblob':
                $values += ['account' => $c('account'), 'key' => $c('key')];
                break;
            case 'drive':
                $values += ['client_id' => $c('client_id'), 'client_secret' => $c('client_secret'), 'scope' => $c('scope', 'drive.file'), 'root_folder_id' => $c('root_folder_id'), 'team_drive' => $c('team_drive'), 'token' => $c('token')];
                break;
            case 'dropbox':
            case 'box':
                $values += ['client_id' => $c('client_id'), 'client_secret' => $c('client_secret'), 'token' => $c('token')];
                break;
            case 'onedrive':
                $values += ['client_id' => $c('client_id'), 'client_secret' => $c('client_secret'), 'token' => $c('token'), 'drive_id' => $c('drive_id'), 'drive_type' => $c('drive_type', 'personal')];
                break;
            case 'pcloud':
                $values += ['client_id' => $c('client_id'), 'client_secret' => $c('client_secret'), 'token' => $c('token'), 'hostname' => $c('hostname', 'api.pcloud.com')];
                break;
            case 'sftp':
                $values += ['host' => $c('host'), 'port' => $c('port', '22'), 'user' => $c('user'), 'pass' => $this->obscure((string) $storage->credential('pass'))];
                $key = trim(str_replace("\r", '', (string) $storage->credential('key_pem')));
                if ($key !== '') {
                    $values['key_pem'] = str_replace("\n", '\\n', $key);
                }
                break;
            case 'ftp':
                $tls = $c('tls', 'explicit');
                $values += ['host' => $c('host'), 'port' => $c('port', $tls === 'implicit' ? '990' : '21'), 'user' => $c('user'), 'pass' => $this->obscure((string) $storage->credential('pass'))];
                $values['tls'] = $tls === 'implicit' ? 'true' : 'false';
                $values['explicit_tls'] = $tls === 'explicit' ? 'true' : 'false';
                if ($c('no_check_certificate')) {
                    $values['no_check_certificate'] = 'true';
                }
                break;
            case 'webdav':
                $values += ['url' => $c('url'), 'vendor' => $c('vendor', 'nextcloud'), 'user' => $c('user'), 'pass' => $this->obscure((string) $storage->credential('pass'))];
                break;
        }

        $lines = ['['.self::remoteName($storage).']'];
        foreach ($values as $key => $value) {
            if ($value !== '' && $value !== null) {
                $lines[] = $key.' = '.$value;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** Password in the reversible format rclone expects (read through stdin, never an argument). */
    protected function obscure(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (Shell::simulating()) {
            return 'obscured-'.substr(hash('sha256', $plain), 0, 12);
        }
        $result = Shell::run('rclone obscure -', 15, $plain);
        if ($result->failed() || trim($result->output) === '') {
            throw new StorageException('rclone could not encode the password: '.$result->message());
        }

        return trim($result->output);
    }

    /** Write a root-only configuration file for one operation and return its path. */
    public function writeConfig(BackupStorage $storage, string $name): string
    {
        $path = self::CONFIG_DIR.'/'.preg_replace('/[^a-z0-9_-]/i', '', $name).'.conf';
        if (Shell::simulating()) {
            return $path;
        }
        Shell::run('mkdir -p '.Shell::arg(self::CONFIG_DIR).' && chmod 700 '.Shell::arg(self::CONFIG_DIR), 10);
        Shell::writeFile($path, $this->config($storage), '0600', 'root:root')->throw('Unable to write the rclone configuration');

        return $path;
    }

    /** OAuth backends refresh their token while running: keep the newest one and remove the file. */
    public function finishConfig(BackupStorage $storage, string $path, bool $remove = true): void
    {
        if (Shell::simulating()) {
            return;
        }
        if (in_array('token', array_keys($storage->definition()['fields'] ?? []), true)) {
            if (preg_match('/^token = (\{.*\})\s*$/m', (string) Shell::readFile($path, 65536), $m) && $m[1] !== $storage->credential('token') && json_decode($m[1], true)) {
                $storage->credentials = array_merge($storage->credentials ?? [], ['token' => $m[1]]);
                $storage->save();
            }
        }
        if ($remove) {
            Shell::run('rm -f -- '.Shell::arg($path), 10);
        }
    }

    public function bwlimit(BackupStorage $storage): string
    {
        $limit = trim((string) ($storage->bwlimit ?: Setting::get('backup_bwlimit', '')));

        return preg_match('/^\d+(\.\d+)?[KMG]?$/i', $limit) ? $limit : '';
    }

    /** rclone command line (without the executable) with the flags shared by every call. */
    public function flags(BackupStorage $storage, string $config, bool $transfer = false): string
    {
        $flags = '--config '.Shell::arg($config).' --ask-password=false';
        if ($transfer) {
            $chunk = $storage->definition()['chunk'] ?? match ($storage->definition()['backend']) {
                's3' => '--s3-chunk-size 64M --s3-upload-concurrency 4',
                'b2' => '--b2-chunk-size 96M',
                'azureblob' => '--azureblob-chunk-size 16M',
                default => '',
            };
            $flags .= ' --retries 5 --retries-sleep 30s --low-level-retries 20 --contimeout 60s --timeout 15m --stats 30s --stats-one-line --stats-log-level NOTICE -v'.($chunk ? ' '.$chunk : '');
            if ($limit = $this->bwlimit($storage)) {
                $flags .= ' --bwlimit '.Shell::arg($limit);
            }
        } else {
            $flags .= ' --retries 2 --low-level-retries 5 --contimeout 30s --timeout 5m';
        }

        return $flags;
    }

    /** Run one short rclone command against a storage. */
    public function run(BackupStorage $storage, string $args, int $timeout = 120, ?string $input = null): ShellResult
    {
        if (Shell::simulating()) {
            return new ShellResult(0, '', '', 'rclone '.$args);
        }
        $config = $this->writeConfig($storage, 'op-'.$storage->id.'-'.bin2hex(random_bytes(6)));
        try {
            return Shell::run('rclone '.$this->flags($storage, $config).' '.$args, $timeout, $input);
        } finally {
            $this->finishConfig($storage, $config);
        }
    }

    protected static function error(ShellResult $result): string
    {
        $text = trim($result->error ?: $result->output);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        // rclone prefixes messages with a timestamp and level
        $last = preg_replace('/^\d{4}\/\d\d\/\d\d \d\d:\d\d:\d\d (ERROR|NOTICE|CRITICAL|Failed to \w+) ?:? ?/', '', end($lines) ?: '');

        return mb_substr($last ?: 'rclone failed with exit code '.$result->exitCode, 0, 500);
    }

    /* ============================================================ operations */

    /**
     * Check a storage by writing, reading and deleting a small file. Returns a short account description.
     */
    public function test(BackupStorage $storage): string
    {
        if (! $this->installed()) {
            throw new StorageException('rclone is not installed. Install it on the Backup page.');
        }
        $root = $this->root($storage);
        if (Shell::simulating()) {
            return $storage->typeName().': '.$root;
        }
        $probe = $this->path($storage, '.gbx-panel-test');
        $result = $this->run($storage, 'rcat '.Shell::arg($probe), 90, 'GBX Panel storage test '.date('c')."\n");
        if ($result->failed()) {
            throw new StorageException('Upload test failed: '.self::error($result));
        }
        $stat = $this->run($storage, 'lsjson --stat '.Shell::arg($probe), 60);
        if ($stat->failed() || ! json_decode($stat->output, true)) {
            throw new StorageException('The test file could not be read back: '.self::error($stat));
        }
        $this->run($storage, 'deletefile '.Shell::arg($probe), 60);

        return $storage->typeName().': '.$root;
    }

    /** Used and total space when the backend reports it. */
    public function about(BackupStorage $storage): ?array
    {
        if (Shell::simulating()) {
            return ['used' => 7340032000, 'total' => 107374182400, 'free' => 100034150400];
        }
        $result = $this->run($storage, 'about --json '.Shell::arg(self::remoteName($storage).':'), 60);
        $data = json_decode($result->output, true);
        if ($result->failed() || ! is_array($data)) {
            // object storage has no quota: measure the backups folder instead
            $size = json_decode($this->run($storage, 'size --json '.Shell::arg($this->root($storage)), 600)->output, true);

            return is_array($size) ? ['used' => (int) ($size['bytes'] ?? 0), 'total' => null, 'free' => null, 'count' => (int) ($size['count'] ?? 0)] : null;
        }

        return ['used' => (int) ($data['used'] ?? 0), 'total' => isset($data['total']) ? (int) $data['total'] : null, 'free' => isset($data['free']) ? (int) $data['free'] : null];
    }

    /** @return list<array{name: string, path: string, size: int, time: int, dir: bool, parts: bool}> */
    public function browse(BackupStorage $storage, string $path = ''): array
    {
        $path = self::cleanPath($path);
        if (Shell::simulating()) {
            return $this->fakeBrowse($path);
        }
        $result = $this->run($storage, 'lsjson --max-depth 1 '.Shell::arg($this->path($storage, $path)), 120);
        if ($result->failed()) {
            // an empty storage has no backups folder yet
            if (str_contains(strtolower($result->error.$result->output), 'directory not found')) {
                return [];
            }
            throw new StorageException(self::error($result));
        }
        $items = [];
        foreach ((array) json_decode($result->output, true) as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ($name === '' || $name === '.gbx-panel-test') {
                continue;
            }
            $items[] = [
                'name' => $name,
                'path' => ltrim($path.'/'.$name, '/'),
                'size' => max(0, (int) ($row['Size'] ?? 0)),
                'time' => strtotime((string) ($row['ModTime'] ?? '')) ?: 0,
                'dir' => (bool) ($row['IsDir'] ?? false),
                'parts' => (bool) ($row['IsDir'] ?? false) && str_ends_with($name, '.parts'),
            ];
        }
        usort($items, fn ($a, $b) => [! $a['dir'] || $a['parts'], $b['time']] <=> [! $b['dir'] || $b['parts'], $a['time']]);

        return $items;
    }

    /** Delete a backup (file, or folder of parts) with its checksum file. */
    public function delete(BackupStorage $storage, string $path): void
    {
        $path = self::cleanPath($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Choose a backup to delete.');
        }
        $target = $this->path($storage, $path);
        $result = str_ends_with($path, '.parts')
            ? $this->run($storage, 'purge '.Shell::arg($target), 600)
            : $this->run($storage, 'deletefile '.Shell::arg($target), 120);
        if ($result->failed()) {
            throw new StorageException(self::error($result));
        }
        $sidecar = preg_replace('/\.parts$/', '', $path).'.sha256';
        $this->run($storage, 'deletefile '.Shell::arg($this->path($storage, $sidecar)), 60);
    }

    /** Stream a backup to the browser (parts are concatenated in order). */
    public function stream(BackupStorage $storage, string $path): void
    {
        $path = self::cleanPath($path);
        if (Shell::simulating()) {
            echo 'Simulated download of '.$path."\n";

            return;
        }
        $config = $this->writeConfig($storage, 'dl-'.$storage->id.'-'.bin2hex(random_bytes(6)));
        $rclone = 'rclone '.$this->flags($storage, $config);
        $target = Shell::arg($this->path($storage, $path));
        $script = str_ends_with($path, '.parts')
            ? "set -o pipefail; {$rclone} lsf --files-only {$target} | grep '^part-' | sort | while IFS= read -r p; do {$rclone} cat {$target}/\"\$p\" || exit 1; done"
            : "{$rclone} cat {$target}";
        $cmd = ['/bin/bash', '-c', $script];
        if (! Shell::isRoot()) {
            $cmd = array_merge(['sudo', '-n'], $cmd);
        }
        try {
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($proc)) {
                while (! feof($pipes[1])) {
                    echo fread($pipes[1], 262144);
                    flush();
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
        } finally {
            $this->finishConfig($storage, $config);
        }
    }

    protected function fakeBrowse(string $path): array
    {
        $now = time();

        return match (true) {
            $path === '' => [
                ['name' => 'database', 'path' => 'database', 'size' => 0, 'time' => $now - 3600, 'dir' => true, 'parts' => false],
                ['name' => 'site', 'path' => 'site', 'size' => 0, 'time' => $now - 3600, 'dir' => true, 'parts' => false],
            ],
            $path === 'site' => [['name' => 'example.com', 'path' => 'site/example.com', 'size' => 0, 'time' => $now - 3600, 'dir' => true, 'parts' => false]],
            $path === 'database' => [['name' => 'mysql', 'path' => 'database/mysql', 'size' => 0, 'time' => $now - 3600, 'dir' => true, 'parts' => false]],
            default => [
                ['name' => 'example.com_'.date('Ymd', $now).'_030000.tar.gz.parts', 'path' => $path.'/example.com_'.date('Ymd', $now).'_030000.tar.gz.parts', 'size' => 0, 'time' => $now - 3600, 'dir' => true, 'parts' => true],
                ['name' => 'example.com_'.date('Ymd', $now - 86400).'_030000.tar.gz', 'path' => $path.'/example.com_'.date('Ymd', $now - 86400).'_030000.tar.gz', 'size' => 193273528, 'time' => $now - 86400, 'dir' => false, 'parts' => false],
            ],
        };
    }
}
