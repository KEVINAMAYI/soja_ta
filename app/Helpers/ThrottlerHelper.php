<?php

namespace App\Helpers;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ThrottlerHelper
{
    /**
     * Default number of seconds after which an attempt auto-clears.
     */
    public const DEFAULT_DECAY_SECONDS = 60;

    /**
     * Default max attempts allowed before a lockout is triggered.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * Build a throttle key out of the given values.
     *
     * @param array $keys
     * @return string
     */
    public static function key(array $keys): string
    {
        $throttleKey = '';
        foreach ($keys as $key) {
            $throttleKey .= Str::lower($key);
        }
        return $throttleKey;
    }

    /**
     * Register a hit against the throttle key, auto-clearing after $decaySeconds.
     *
     * @param array $keys
     * @param int $decaySeconds
     * @return int
     */
    public static function hit(array $keys, int $decaySeconds = self::DEFAULT_DECAY_SECONDS): int
    {
        ThrottlerHelper::ensureIsNotRateLimited($keys);
        return RateLimiter::hit(self::key($keys), $decaySeconds);
    }

    /**
     * Clear the throttle attempts for the given keys.
     *
     * @param array $keys
     * @return void
     */
    public static function clear(array $keys): void
    {
        RateLimiter::clear(self::key($keys));
    }

    /**
     * Ensure the request is not rate limited, throwing a validation exception otherwise.
     *
     * @param array $keys
     * @param int $maxAttempts
     * @param string $field
     * @return void
     *
     * @throws ValidationException
     */
    public static function ensureIsNotRateLimited(
        array $keys,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        string $field = 'email'
    ): void {
        $throttleKey = self::key($keys);

        if (!RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            $field => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }
}
