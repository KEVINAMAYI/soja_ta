<?php

namespace App\Services;

use App\Models\OrganizationSetting;

class CheckInApprovalSettings
{
    public const SETTING_KEY = 'checkin_approval_settings';

    /**
     * Default shape of the settings JSON, matching the UI:
     *
     * - enabled: bool — master toggle
     * - auto_reject_after_minutes: int|null — if lateness >= this, skip all
     *   windows and auto-reject immediately (null = disabled)
     * - department_ids: int[] — policy scope (empty = all departments)
     * - windows: array of up to 3 windows:
     *     [
     *       'enabled'              => bool,
     *       'min_minutes_late'     => int,   // NEW — entry threshold for this window
     *       'approver_role'        => string,
     *       'timeout_minutes'      => int,
     *       'on_timeout'           => 'approve' | 'reject' | 'escalate',
     *       'notify_email'         => bool,
     *       'notify_email_addresses' => string[],
     *       'notify_sms'           => bool,
     *       'notify_sms_numbers'   => string[],
     *     ]
     *
     * Window routing logic (in CheckInApprovalService::resolveEntryWindow):
     *   1. If $minutesLate >= auto_reject_after_minutes → immediate rejection
     *   2. Find the LAST enabled window whose min_minutes_late <= $minutesLate
     *   3. Fall back to the first enabled window if none match
     */
    public static function defaults(): array
    {
        return [
            'enabled'                    => false,
            'auto_reject_after_minutes'  => null,   // null = disabled
            'department_ids'             => [],
            'windows'                    => [
                [
                    'enabled'                 => true,
                    'min_minutes_late'        => 0,    // catches everything from 0+ min late
                    'approver_role'           => 'Line Manager',
                    'timeout_minutes'         => 15,
                    'on_timeout'              => 'escalate',
                    'notify_email'            => true,
                    'notify_email_addresses'  => [],
                    'notify_sms'              => false,
                    'notify_sms_numbers'      => [],
                ],
                [
                    'enabled'                 => false,
                    'min_minutes_late'        => 20,   // catches 20+ min late
                    'approver_role'           => 'HR Manager',
                    'timeout_minutes'         => 30,
                    'on_timeout'              => 'reject',
                    'notify_email'            => true,
                    'notify_email_addresses'  => [],
                    'notify_sms'              => false,
                    'notify_sms_numbers'      => [],
                ],
                [
                    'enabled'                 => false,
                    'min_minutes_late'        => 40,   // catches 40+ min late
                    'approver_role'           => 'Department Head',
                    'timeout_minutes'         => 60,
                    'on_timeout'              => 'approve',
                    'notify_email'            => false,
                    'notify_email_addresses'  => [],
                    'notify_sms'              => false,
                    'notify_sms_numbers'      => [],
                ],
            ],
        ];
    }

    /**
     * Fetch settings for an organization, merged with defaults so the UI
     * always has a complete shape to bind to.
     */
    public static function get(int $organizationId): array
    {
        $row = OrganizationSetting::where('organization_id', $organizationId)
            ->where('key', self::SETTING_KEY)
            ->first();

        $stored = $row
            ? (is_array($row->value) ? $row->value : (json_decode($row->value, true) ?? []))
            : [];

        return self::mergeWithDefaults($stored);
    }

    public static function save(int $organizationId, array $settings): void
    {
        $merged = self::mergeWithDefaults($settings);

        $setting = OrganizationSetting::firstOrNew([
            'organization_id' => $organizationId,
            'key'             => self::SETTING_KEY,
        ]);

        $setting->type  = 'json';
        $setting->value = json_encode($merged);
        $setting->save();
    }

    public static function isEnabled(int $organizationId): bool
    {
        return (bool) self::get($organizationId)['enabled'];
    }

    /**
     * Determine whether the given employee's department is within the
     * configured policy scope. Empty department_ids = all departments.
     */
    public static function appliesToDepartment(array $settings, ?int $departmentId): bool
    {
        $scoped = $settings['department_ids'] ?? [];
        if (empty($scoped)) {
            return true;
        }

        return $departmentId !== null && in_array($departmentId, $scoped, false);
    }

    private static function mergeWithDefaults(array $stored): array
    {
        $defaults = self::defaults();
        $merged   = array_merge($defaults, $stored);

        // Normalize auto_reject_after_minutes (null = disabled, else positive int)
        if (isset($merged['auto_reject_after_minutes']) && $merged['auto_reject_after_minutes'] !== null) {
            $merged['auto_reject_after_minutes'] = max(1, (int) $merged['auto_reject_after_minutes']);
        } else {
            $merged['auto_reject_after_minutes'] = null;
        }

        $mergedWindows = [];
        for ($i = 0; $i < 3; $i++) {
            $w = array_merge($defaults['windows'][$i], $stored['windows'][$i] ?? []);

            // Normalize types
            $w['enabled']          = (bool) $w['enabled'];
            $w['min_minutes_late'] = max(0, (int) ($w['min_minutes_late'] ?? 0));
            $w['timeout_minutes']  = (int) $w['timeout_minutes'];
            $w['notify_email']     = (bool) $w['notify_email'];
            $w['notify_sms']       = (bool) $w['notify_sms'];

            $mergedWindows[$i] = $w;
        }
        $merged['windows'] = $mergedWindows;

        // Normalize other types
        $merged['enabled']        = (bool) $merged['enabled'];
        $merged['department_ids'] = array_values(array_map('intval', $merged['department_ids'] ?? []));

        return $merged;
    }
}
