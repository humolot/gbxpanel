<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Http;

/**
 * OAuth helpers for the cloud drives.
 *
 * Google Drive can be connected in two ways: the panel runs the authorization itself (needs a
 * domain with SSL, because Google only accepts HTTPS redirect URIs), or the user runs
 * "rclone authorize" on a computer with a browser and pastes the result here.
 */
class OAuthTokens
{
    public const GOOGLE_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';

    public static function googleScope(string $scope): string
    {
        return 'https://www.googleapis.com/auth/'.($scope === 'drive' ? 'drive' : 'drive.file');
    }

    public static function googleAuthUrl(string $clientId, string $redirect, string $scope, string $state): string
    {
        return self::GOOGLE_AUTH.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => self::googleScope($scope),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Exchange an authorization code for a token in the format rclone stores. */
    public static function googleExchange(string $clientId, string $clientSecret, string $code, string $redirect): string
    {
        $response = Http::asForm()->timeout(30)->post(self::GOOGLE_TOKEN, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect,
        ]);
        $data = (array) $response->json();
        if (! $response->successful() || empty($data['access_token'])) {
            throw new StorageException('Google refused the authorization: '.($data['error_description'] ?? $data['error'] ?? $response->status()));
        }
        if (empty($data['refresh_token'])) {
            throw new StorageException('Google did not return a refresh token. Remove the panel from the account access (myaccount.google.com/permissions) and connect again.');
        }

        return self::token($data);
    }

    /** @param array<string, mixed> $data */
    public static function token(array $data): string
    {
        return (string) json_encode([
            'access_token' => (string) $data['access_token'],
            'token_type' => (string) ($data['token_type'] ?? 'Bearer'),
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expiry' => now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 3600)))->toRfc3339String(),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Token pasted from "rclone authorize": accepts the whole output or only the JSON.
     */
    public static function parsePasted(string $text): string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $json = $start !== false && $end !== false && $end > $start ? substr($text, $start, $end - $start + 1) : '';
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['access_token'])) {
            throw new StorageException('That does not look like a token. Paste the whole answer of the rclone authorize command.');
        }
        if (empty($data['refresh_token'])) {
            throw new StorageException('The token has no refresh token, so it would stop working within an hour. Run the authorize command again.');
        }

        return (string) json_encode([
            'access_token' => (string) $data['access_token'],
            'token_type' => (string) ($data['token_type'] ?? 'Bearer'),
            'refresh_token' => (string) $data['refresh_token'],
            'expiry' => (string) ($data['expiry'] ?? now()->addHour()->toRfc3339String()),
        ], JSON_UNESCAPED_SLASHES);
    }

    /** OneDrive needs the drive the token belongs to; it is read from Microsoft Graph. */
    public static function oneDriveInfo(string $token): array
    {
        $access = (string) (json_decode($token, true)['access_token'] ?? '');
        $response = Http::withToken($access)->timeout(30)->get('https://graph.microsoft.com/v1.0/me/drive');
        $data = (array) $response->json();
        if (! $response->successful() || empty($data['id'])) {
            throw new StorageException('The OneDrive account could not be read with this token. Paste a freshly generated token, or fill in the Drive ID by hand.');
        }

        return ['drive_id' => (string) $data['id'], 'drive_type' => (string) ($data['driveType'] ?? 'personal')];
    }

    /** Command shown in the panel for the paste method. */
    public static function authorizeCommand(string $backend, string $clientId = '', string $clientSecret = ''): string
    {
        $command = 'rclone authorize "'.$backend.'"';

        return $clientId !== '' ? $command.' "'.$clientId.'" "'.($clientSecret !== '' ? $clientSecret : '').'"' : $command;
    }
}
