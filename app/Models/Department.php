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
        'unit_id',
        'description',
        'manager_id',
        'default_shift_id',
    ];

    protected static function booted()
    {
        // Superseded by the Unit>Department>Section>Subsection hierarchy filter — Department
        // filtering no longer needs name-based grouping. Commented out, not deleted, pending
        // end-to-end testing of the new hierarchy.
        // static::creating(function (Department $department) {
        //     if (blank($department->department_derived)) {
        //         $department->department_derived = $department->name;
        //     }
        // });
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function defaultShift()
    {
        return $this->belongsTo(Shift::class, 'default_shift_id');
    }

    /**
     * Superseded by the Unit>Department>Section>Subsection hierarchy filter.
     * Commented out, not deleted — remove for good once the new hierarchy is confirmed working.
     *
     * Departments for an organization, grouped by their derived (canonical) name.
     *
     * Returns plain ['id' => ..., 'name' => ...] arrays rather than Department models —
     * grouping turns the top-level result into a Collection-of-Collections-of-models, a
     * shape Livewire's Eloquent synth can't dehydrate when this is assigned to a public
     * component property (throws "Collection::getMorphClass does not exist"). Plain
     * arrays sidestep that entirely.
     */
    // public static function groupedByDerived(int $organizationId): Collection
    // {
    //     return static::where('organization_id', $organizationId)
    //         ->orderBy('department_derived')
    //         ->orderBy('name')
    //         ->get(['id', 'name', 'department_derived'])
    //         ->groupBy(fn (Department $department) => $department->department_derived ?: $department->name)
    //         ->map(fn (Collection $members) => $members
    //             ->map(fn (Department $department) => ['id' => $department->id, 'name' => $department->name])
    //             ->values());
    // }

}
