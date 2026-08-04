<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'department_derived',
        'organization_id',
        'description',
        'manager_id',
    ];

    protected static function booted()
    {
        static::creating(function (Department $department) {
            if (blank($department->department_derived)) {
                $department->department_derived = $department->name;
            }
        });
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Departments for an organization, grouped by their derived (canonical) name.
     *
     * Returns plain ['id' => ..., 'name' => ...] arrays rather than Department models —
     * grouping turns the top-level result into a Collection-of-Collections-of-models, a
     * shape Livewire's Eloquent synth can't dehydrate when this is assigned to a public
     * component property (throws "Collection::getMorphClass does not exist"). Plain
     * arrays sidestep that entirely.
     */
    public static function groupedByDerived(int $organizationId): Collection
    {
        return static::where('organization_id', $organizationId)
            ->orderBy('department_derived')
            ->orderBy('name')
            ->get(['id', 'name', 'department_derived'])
            ->groupBy(fn (Department $department) => $department->department_derived ?: $department->name)
            ->map(fn (Collection $members) => $members
                ->map(fn (Department $department) => ['id' => $department->id, 'name' => $department->name])
                ->values());
    }

}
