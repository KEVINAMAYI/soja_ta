<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationSetting;

class CheckInApprovalSettings
{
    public const SETTING_KEY = 'checkin_approval_settings';

    /**
     * Default shape of the settings JSON, matching the UI:
     *
     * - enabled: bool — master toggle ("Enabled for this tenant")
     * - grace_period_minutes: int — late clock-in grace period
     * - department_ids: int[] — policy scope (empty = all departments)
     * - windows: array of up to 3 windows:
     *     [
     *       'enabled' => bool,
     *       'approver_role' => string,   // Line Manager / HR Manager / Department Head / custom
     *       'timeout_minutes' => int,
     *       'on_timeout' => 'approve' | 'reject' | 'escalate',
     *       'notify_email' => bool,
     *       'notify_email_addresses' => string[],
     *       'notify_sms' => bool,
     *       'notify_sms_numbers' => string[],
     *     ]
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'department_ids' => [],
            'windows' => [
                [
                    'enabled' => true,
                    'approver_role' => 'Line Manager',
                    'timeout_minutes' => 15,
                    'on_timeout' => 'escalate',
                    'notify_email' => true,
                    'notify_email_addresses' => [],
                    'notify_sms' => false,
                    'notify_sms_numbers' => [],
                ],
                [
                    'enabled' => false,
                    'approver_role' => 'HR Manager',
                    'timeout_minutes' => 30,
                    'on_timeout' => 'reject',
                    'notify_email' => true,
                    'notify_email_addresses' => [],
                    'notify_sms' => false,
                    'notify_sms_numbers' => [],
                ],
                [
                    'enabled' => false,
                    'approver_role' => 'Department Head',
                    'timeout_minutes' => 60,
                    'on_timeout' => 'approve',
                    'notify_email' => false,
                    'notify_email_addresses' => [],
                    'notify_sms' => false,
                    'notify_sms_numbers' => [],
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

        $stored = $row ? (is_array($row->value) ? $row->value : (json_decode($row->value, true) ?? [])) : [];

        return self::mergeWithDefaults($stored);
    }

    public static function save(int $organizationId, array $settings): void
    {
        $merged = self::mergeWithDefaults($settings);

        $setting = OrganizationSetting::firstOrNew([
            'organization_id' => $organizationId,
            'key' => self::SETTING_KEY,
        ]);

        $setting->type = 'json';
        $setting->value = json_encode($merged);
        $setting->save();
    }

    public static function isEnabled(int $organizationId): bool
    {
        return (bool)self::get($organizationId)['enabled'];
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

        $merged = array_merge($defaults, $stored);

        $mergedWindows = [];
        for ($i = 0; $i < 3; $i++) {
            $w = array_merge($defaults['windows'][$i], $stored['windows'][$i] ?? []);
            $w['enabled'] = (bool)$w['enabled'];
            $w['timeout_minutes'] = (int)$w['timeout_minutes'];
            $w['notify_email'] = (bool)$w['notify_email'];
            $w['notify_sms'] = (bool)$w['notify_sms'];
            $mergedWindows[$i] = $w;
        }
        $merged['windows'] = $mergedWindows;

        // normalize types
        $merged['enabled'] = (bool)$merged['enabled'];
        $merged['department_ids'] = array_values(array_map('intval', $merged['department_ids'] ?? []));

        return $merged;
    }
}
