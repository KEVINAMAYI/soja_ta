<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class LeaveTypesSeeder extends Seeder
{
    /**
     * Default leave types, seeded per organization. These mirror the
     * previously-hardcoded leave type enum so existing behavior is preserved.
     */
    public static array $defaults = [
        ['code' => 'sick', 'name' => 'Sick Off', 'icon' => '🤒', 'annual_entitlement_days' => null],
        ['code' => 'offshift', 'name' => 'Off Shift', 'icon' => '🌙', 'annual_entitlement_days' => null],
        ['code' => 'annual', 'name' => 'Annual Leave', 'icon' => '🏖️', 'annual_entitlement_days' => 21],
        ['code' => 'maternity', 'name' => 'Maternity Leave', 'icon' => '🤰', 'annual_entitlement_days' => 90],
        ['code' => 'paternity', 'name' => 'Paternity Leave', 'icon' => '👨‍🍼', 'annual_entitlement_days' => 14],
        ['code' => 'compassionate', 'name' => 'Compassionate Leave', 'icon' => '🕯️', 'annual_entitlement_days' => 7],
        ['code' => 'study', 'name' => 'Study Leave', 'icon' => '📚', 'annual_entitlement_days' => 10],
        ['code' => 'unpaid', 'name' => 'Unpaid Leave', 'icon' => '💸', 'annual_entitlement_days' => null],
        ['code' => 'personal', 'name' => 'Personal Leave', 'icon' => '👤', 'annual_entitlement_days' => 5],
    ];

    public function run(): void
    {
        Organization::all()->each(function ($org) {
            static::seedForOrganization($org->id);
        });
    }

    public static function seedForOrganization(int $organizationId): void
    {
        foreach (static::$defaults as $type) {
            LeaveType::firstOrCreate(
                ['organization_id' => $organizationId, 'code' => $type['code']],
                $type
            );
        }
    }
}
