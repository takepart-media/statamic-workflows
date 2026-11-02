<?php

namespace TakepartMedia\Workflows\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Statamic\Auth\Passwords\PasswordReset;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * The password broker behind Statamic's activation links, as the account
 * reminder (Workflow\AccountReminder) sees it.
 *
 * By default this is whatever Statamic is configured with
 * (`statamic.users.passwords.activations`), so the link lives exactly as long
 * as a CP invitation does. `workflows.account_reminder.expire_hours` replaces
 * that duration: register() then installs a copy of the broker with the new
 * expiry and points Statamic at it — the activation route checks the token
 * against that same broker, so there is no way to change the duration for the
 * reminder alone.
 */
final class ActivationBroker
{
    public const OWN = 'workflows_activations';

    public static function register(): void
    {
        $hours = config('workflows.account_reminder.expire_hours');

        if ($hours === null || $hours === '' || (int) $hours < 1) {
            return;
        }

        config(['auth.passwords.'.self::OWN => array_merge(self::config(), ['expire' => (int) $hours * 60])]);

        $current = config('statamic.users.passwords.'.PasswordReset::BROKER_ACTIVATIONS);

        config(['statamic.users.passwords.'.PasswordReset::BROKER_ACTIVATIONS => is_array($current)
            ? array_merge($current, ['cp' => self::OWN])
            : self::OWN]);
    }

    /** The broker's name — resolved like User::generateActivateAccountToken(). */
    public static function name(): string
    {
        $broker = config('statamic.users.passwords.'.PasswordReset::BROKER_ACTIVATIONS);

        if (is_array($broker)) {
            $broker = $broker['cp'] ?? null;
        }

        return is_string($broker) && $broker !== '' ? $broker : (string) config('auth.defaults.passwords');
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return (array) config('auth.passwords.'.self::name(), []);
    }

    public static function expireMinutes(): int
    {
        return max(1, (int) (self::config()['expire'] ?? 60));
    }

    /** Rounded up, so the mail never promises less than the link lasts. */
    public static function expireHours(): int
    {
        return (int) ceil(self::expireMinutes() / 60);
    }

    /**
     * Whether the user still holds an activation link that works — the last
     * reminder's or the CP invitation's. Read from the broker's token table;
     * a broker without one (cache driver) falls back to remembering our own
     * sends (markSent()).
     */
    public static function linkStillValid(UserContract $user): bool
    {
        $config = self::config();

        if (! isset($config['table'])) {
            return Cache::has(self::cacheKey($user));
        }

        try {
            $created = DB::connection($config['connection'] ?? null)
                ->table($config['table'])
                ->where('email', $user->email())
                ->value('created_at');
        } catch (\Throwable) {
            // No token table yet: then there is no token either.
            return false;
        }

        return $created !== null && Carbon::parse($created)->addMinutes(self::expireMinutes())->isFuture();
    }

    /** Only needed without a token table — see linkStillValid(). */
    public static function markSent(UserContract $user): void
    {
        if (! isset(self::config()['table'])) {
            Cache::put(self::cacheKey($user), true, now()->addMinutes(self::expireMinutes()));
        }
    }

    private static function cacheKey(UserContract $user): string
    {
        return 'workflows:account_reminder:'.$user->id();
    }
}
