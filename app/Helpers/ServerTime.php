<?php

namespace App\Helpers;

use Carbon\Carbon;

class ServerTime
{
    /**
     * Default timezone for the application
     */
    const TIMEZONE = 'Africa/Nairobi';

    /**
     * Get current server time in the configured timezone
     *
     * @return Carbon
     */
    public static function now()
    {
        return Carbon::now(self::TIMEZONE);
    }

    /**
     * Get current server time as a formatted string
     *
     * @param string $format
     * @return string
     */
    public static function nowFormatted($format = 'Y-m-d H:i:s')
    {
        return self::now()->format($format);
    }

    /**
     * Get current server time as ISO 8601 string
     *
     * @return string
     */
    public static function nowIso()
    {
        return self::now()->toIso8601String();
    }

    /**
     * Get current server timestamp
     *
     * @return int
     */
    public static function timestamp()
    {
        return self::now()->timestamp;
    }

    /**
     * Get current date only
     *
     * @return string
     */
    public static function today()
    {
        return self::now()->toDateString();
    }

    /**
     * Get current time only
     *
     * @return string
     */
    public static function currentTime()
    {
        return self::now()->toTimeString();
    }

    /**
     * Parse a datetime string to server timezone
     *
     * @param string $datetime
     * @return Carbon
     */
    public static function parse($datetime)
    {
        return Carbon::parse($datetime, self::TIMEZONE);
    }

    /**
     * Convert any datetime to server timezone
     *
     * @param mixed $datetime
     * @return Carbon
     */
    public static function toServerTime($datetime)
    {
        if ($datetime instanceof Carbon) {
            return $datetime->setTimezone(self::TIMEZONE);
        }

        return Carbon::parse($datetime)->setTimezone(self::TIMEZONE);
    }

    /**
     * Check if current time is within a time range
     *
     * @param string $startTime (H:i:s format)
     * @param string $endTime (H:i:s format)
     * @return bool
     */
    public static function isWithinTimeRange($startTime, $endTime)
    {
        $now = self::now();
        $start = Carbon::createFromFormat('H:i:s', $startTime, self::TIMEZONE);
        $end = Carbon::createFromFormat('H:i:s', $endTime, self::TIMEZONE);

        return $now->between($start, $end);
    }

    /**
     * Get time difference in minutes from now
     *
     * @param string|Carbon $datetime
     * @return int
     */
    public static function minutesFromNow($datetime)
    {
        $target = $datetime instanceof Carbon ? $datetime : Carbon::parse($datetime, self::TIMEZONE);
        return self::now()->diffInMinutes($target, false);
    }

    /**
     * Get formatted response for API
     *
     * @return array
     */
    public static function getApiResponse()
    {
        return [
            'server_time' => self::nowIso(),
            'timezone' => self::TIMEZONE,
            'timestamp' => self::timestamp(),
            'date' => self::today(),
            'time' => self::currentTime(),
        ];
    }
}
