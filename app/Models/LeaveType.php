<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = ['organization_id', 'name', 'normalized_name'];

    public static function defaults(): array
    {
        return [
            'sick' => ['id' => 'sick', 'name' => 'Sick Off', 'icon' => '🤒'],
            'offshift' => ['id' => 'offshift', 'name' => 'Off Shift', 'icon' => '🌙'],
            'annual' => ['id' => 'annual', 'name' => 'Annual Leave', 'icon' => '🏖️'],
            'maternity' => ['id' => 'maternity', 'name' => 'Maternity Leave', 'icon' => '🤰'],
            'paternity' => ['id' => 'paternity', 'name' => 'Paternity Leave', 'icon' => '👨‍🍼'],
            'compassionate' => ['id' => 'compassionate', 'name' => 'Compassionate Leave', 'icon' => '🕯️'],
            'study' => ['id' => 'study', 'name' => 'Study Leave', 'icon' => '📚'],
            'unpaid' => ['id' => 'unpaid', 'name' => 'Unpaid Leave', 'icon' => '💸'],
            'personal' => ['id' => 'personal', 'name' => 'Personal Leave', 'icon' => '👤'],
        ];
    }

    public static function options(int $organizationId): array
    {
        $options = static::defaults();
        $names = array_map(fn ($option) => mb_strtolower($option['name']), $options);

        foreach (static::where('organization_id', $organizationId)->orderBy('name')->get() as $type) {
            if (! in_array($type->normalized_name, $names, true)) {
                $id = 'custom_'.$type->id;
                $options[$id] = ['id' => $id, 'name' => $type->name, 'icon' => '📄'];
            }
        }

        return $options;
    }

    public static function resolveName(int $organizationId, string $name): string
    {
        $name = trim($name);
        $normalized = mb_strtolower($name);
        $type = static::where('organization_id', $organizationId)->where('normalized_name', $normalized)->first();

        if ($type) {
            return $type->name;
        }

        $existing = collect(static::defaults())->first(fn ($option) => mb_strtolower($option['name']) === $normalized);
        $name = $existing['name'] ?? Leave::where('organization_id', $organizationId)
            ->whereRaw('LOWER(leave_type) = ?', [$normalized])->value('leave_type') ?? $name;

        return static::firstOrCreate(
            ['organization_id' => $organizationId, 'normalized_name' => $normalized],
            ['name' => $name],
        )->name;
    }
}
