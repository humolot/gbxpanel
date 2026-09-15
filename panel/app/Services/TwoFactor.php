<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Time-based one-time passwords (RFC 6238, SHA-1, 30 s, 6 digits) for authenticator apps
 * such as Google Authenticator, Microsoft Authenticator, Authy, 1Password or Bitwarden.
 */
class TwoFactor
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Accepted clock drift in periods before and after the current one. */
    public const WINDOW = 1;

    public const RECOVERY_CODES = 10;

    public const TRUST_COOKIE = 'gbx_2fa_trust';

    public const TRUST_DAYS = 30;

    protected const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[\s=-]+/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 secret');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Time step matched by the code, or null. Steps at or before $lastStep are rejected so a code
     * cannot be used twice.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $current = self::step($time);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $current + $i;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function issuer(): string
    {
        return (string) Setting::get('panel_title', 'GBX Panel');
    }

    /** otpauth:// URI encoded in the QR code. */
    public static function uri(Model $user, string $secret): string
    {
        $issuer = self::issuer();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: request()->getHost();
        $label = rawurlencode($issuer).':'.rawurlencode($user->username.'@'.$host);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /* ======================================================== recovery codes */

    /** @return array{0: list<string>, 1: list<string>} plain codes and their hashes */
    public static function makeRecoveryCodes(): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $plain[] = strtolower(Str::random(5).'-'.Str::random(5));
        }

        return [$plain, array_map([self::class, 'hashRecoveryCode'], $plain)];
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', strtolower(trim($code)), TerminalManager::key());
    }

    /** Remove a matching recovery code from the user; true when it was valid. */
    public static function useRecoveryCode(Model $user, string $code): bool
    {
        $hash = self::hashRecoveryCode($code);
        $codes = $user->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /* ========================================================= trusted devices */

    /** Cookie value; tied to the current secret so resetting 2FA revokes every trusted browser. */
    public static function trustToken(Model $user): string
    {
        $expires = time() + self::TRUST_DAYS * 86400;

        return $user->id.'|'.$expires.'|'.hash_hmac('sha256', $user->id.'|'.$expires.'|'.$user->two_factor_secret, TerminalManager::key());
    }

    public static function trusted(Model $user, ?string $token): bool
    {
        if (! $token || ! $user->hasTwoFactor()) {
            return false;
        }
        $parts = explode('|', $token);
        if (count($parts) !== 3 || (int) $parts[0] !== $user->id || (int) $parts[1] < time()) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts[0].'|'.$parts[1].'|'.$user->two_factor_secret, TerminalManager::key()), $parts[2]);
    }

    /** Administrators can require every account to use two-factor authentication. */
    public static function required(): bool
    {
        return (bool) Setting::get('two_factor_required', false);
    }
}
