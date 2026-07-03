<?php

namespace App\Services;

use App\Models\DepartmentLeaveApprovalSetting;
use App\Models\OrganizationSetting;

class LeaveApprovalSettings
{
    public const SETTING_KEY = 'leave_approval_settings';

    /**
     * Default shape of the settings JSON, matching the UI:
     *
     * - enabled: bool — master toggle for the multi-level approval chain
     * - levels: array of up to 3 levels:
     *     [
     *       'enabled' => bool,
     *       'approver_type' => 'role' | 'user',
     *       'approver_role' => string|null,        // spatie role name, used when approver_type = 'role'
     *       'approver_user_id' => int|null,        // specific user id, used when approver_type = 'user'
     *       'notify_email' => bool,                // send to extra manually configured addresses too
     *       'notify_email_addresses' => string[],
     *     ]
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'levels' => [
                [
                    'enabled' => true,
                    'approver_type' => 'role',
                    'approver_role' => 'supervisor',
                    'approver_user_id' => null,
                    'notify_email' => false,
                    'notify_email_addresses' => [],
                ],
                [
                    'enabled' => false,
                    'approver_type' => 'role',
                    'approver_role' => 'department-manager',
                    'approver_user_id' => null,
                    'notify_email' => false,
                    'notify_email_addresses' => [],
                ],
                [
                    'enabled' => false,
                    'approver_type' => 'role',
                    'approver_role' => 'admin',
                    'approver_user_id' => null,
                    'notify_email' => false,
                    'notify_email_addresses' => [],
                ],
            ],
        ];
    }

    /**
     * Fetch settings for an organization, merged with defaults so the UI
     * always has a complete shape to bind to.
     *
     * When $departmentId is given and that department has its own override
     * row, the override is returned instead. A department with no override
     * inherits the organization's own current settings (not the raw
     * defaults()) — "no override" means "same as the org today".
     */
    public static function get(int $organizationId, ?int $departmentId = null): array
    {
        if ($departmentId !== null) {
            $override = DepartmentLeaveApprovalSetting::where('organization_id', $organizationId)
                ->where('department_id', $departmentId)
                ->first();

            if ($override) {
                return self::mergeWithDefaults([
                    'enabled' => $override->enabled,
                    'levels' => $override->levels,
                ]);
            }
        }

        return self::getOrganizationSettings($organizationId);
    }

    private static function getOrganizationSettings(int $organizationId): array
    {
        $row = OrganizationSetting::where('organization_id', $organizationId)
            ->where('key', self::SETTING_KEY)
            ->first();

        // OrganizationSetting::getValueAttribute() already json_decodes for type='json',
        // so $row->value may already be an array — only decode if it's still a raw string.
        $stored = $row ? (is_array($row->value) ? $row->value : (json_decode($row->value, true) ?? [])) : [];

        return self::mergeWithDefaults($stored);
    }

    /**
     * Save settings for an organization, or for a single department's
     * override when $departmentId is given.
     */
    public static function save(int $organizationId, array $settings, ?int $departmentId = null): void
    {
        $merged = self::mergeWithDefaults($settings);

        if ($departmentId !== null) {
            DepartmentLeaveApprovalSetting::updateOrCreate(
                ['organization_id' => $organizationId, 'department_id' => $departmentId],
                ['enabled' => $merged['enabled'], 'levels' => $merged['levels']]
            );

            return;
        }

        $setting = OrganizationSetting::firstOrNew([
            'organization_id' => $organizationId,
            'key' => self::SETTING_KEY,
        ]);

        $setting->type = 'json';
        $setting->value = json_encode($merged);
        $setting->save();
    }

    public static function isEnabled(int $organizationId, ?int $departmentId = null): bool
    {
        return (bool) self::get($organizationId, $departmentId)['enabled'];
    }

    /**
     * Whether the given department has its own approval-chain override,
     * as opposed to inheriting the organization-wide default.
     */
    public static function hasDepartmentOverride(int $organizationId, int $departmentId): bool
    {
        return DepartmentLeaveApprovalSetting::where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->exists();
    }

    /**
     * Remove a department's override so it falls back to the
     * organization-wide default again.
     */
    public static function resetDepartmentOverride(int $organizationId, int $departmentId): void
    {
        DepartmentLeaveApprovalSetting::where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->delete();
    }

    /**
     * IDs of every department in the organization that has its own
     * approval-chain override configured.
     */
    public static function departmentIdsWithOverride(int $organizationId): array
    {
        return DepartmentLeaveApprovalSetting::where('organization_id', $organizationId)
            ->pluck('department_id')
            ->all();
    }

    /**
     * First enabled level (1-based), or null if none enabled.
     */
    public static function firstEnabledLevel(array $settings): ?int
    {
        for ($i = 0; $i < 3; $i++) {
            if (!empty($settings['levels'][$i]['enabled'])) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Next enabled level strictly after $from (1-based), or null if none remain.
     */
    public static function nextEnabledLevel(array $settings, int $from): ?int
    {
        for ($i = $from; $i < 3; $i++) {
            if (!empty($settings['levels'][$i]['enabled'])) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function mergeWithDefaults(array $stored): array
    {
        $defaults = self::defaults();

        $merged = array_merge($defaults, $stored);

        $mergedLevels = [];
        for ($i = 0; $i < 3; $i++) {
            $level = array_merge(
                $defaults['levels'][$i],
                $stored['levels'][$i] ?? []
            );

            $level['enabled'] = (bool) $level['enabled'];
            $level['approver_type'] = in_array($level['approver_type'], ['role', 'user'], true)
                ? $level['approver_type']
                : 'role';

            // Only the field matching the current approver_type is meaningful —
            // clear the other one so stale values (e.g. left over from switching
            // "Role" -> "Specific user" in the form) never persist or leak into
            // notifications/authorization checks.
            $level['approver_user_id'] = ($level['approver_type'] === 'user'
                    && $level['approver_user_id'] !== null && $level['approver_user_id'] !== '')
                ? (int) $level['approver_user_id']
                : null;
            $level['approver_role'] = ($level['approver_type'] === 'role' && !empty($level['approver_role']))
                ? $level['approver_role']
                : null;

            $level['notify_email'] = (bool) $level['notify_email'];
            $level['notify_email_addresses'] = array_values($level['notify_email_addresses'] ?? []);

            $mergedLevels[$i] = $level;
        }
        $merged['levels'] = $mergedLevels;

        $merged['enabled'] = (bool) $merged['enabled'];

        return $merged;
    }
}
