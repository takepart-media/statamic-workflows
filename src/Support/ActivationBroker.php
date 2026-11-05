<?php

namespace TakepartMedia\Workflows\Support;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Statamic\Auth\Passwords\PasswordReset;
use Statamic\Auth\Passwords\TokenRepository as StatamicTokenRepository;
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
     * reminder's or the CP invitation's.
     *
     * Asked of the broker's own token repository, because where the token lives
     * depends on the user provider: with Statamic's file users it is a YAML file
     * (storage/statamic/password_resets/<table>.yaml, Statamic's
     * TokenRepository), with database users Laravel's token table. A repository
     * that is neither (e.g. the cache driver) falls back to remembering our own
     * sends (markSent()).
     */
    public static function linkStillValid(UserContract $user): bool
    {
        $created = self::tokenCreatedAt($user);

        if ($created === false) {
            return Cache::has(self::cacheKey($user));
        }

        return $created !== null && $created->copy()->addMinutes(self::expireMinutes())->isFuture();
    }

    /** Only needed for a repository linkStillValid() cannot read. */
    public static function markSent(UserContract $user): void
    {
        if (self::tokenCreatedAt($user) === false) {
            Cache::put(self::cacheKey($user), true, now()->addMinutes(self::expireMinutes()));
        }
    }

    /**
     * When the user's current activation token was created: a Carbon, null for
     * no token, false when the repository cannot be read this way. The
     * repositories keep their records behind protected methods, so they are
     * read from inside (Closure::call) rather than duplicating their storage
     * paths and table names here.
     */
    private static function tokenCreatedAt(UserContract $user): Carbon|false|null
    {
        $email = (string) $user->email();

        try {
            $repository = Password::broker(self::name())->getRepository();

            if ($repository instanceof StatamicTokenRepository) {
                $record = (fn () => $this->getResets()->get($email))->call($repository);

                return $record === null ? null : Carbon::createFromTimestamp($record['created_at'], config('app.timezone'));
            }

            if ($repository instanceof DatabaseTokenRepository) {
                $record = (fn () => $this->getTable()->where('email', $email)->first())->call($repository);

                return $record === null ? null : Carbon::parse($record->created_at);
            }
        } catch (\Throwable) {
            // No token table yet, or a broker that cannot be built: then there
            // is no token either.
            return null;
        }

        return false;
    }

    private static function cacheKey(UserContract $user): string
    {
        return 'workflows:account_reminder:'.$user->id();
    }
}
