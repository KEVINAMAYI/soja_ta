<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'tier',
        'tagline',
        'price',
        'max_locations',
        'max_devices',
        'is_most_popular',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'is_most_popular' => 'boolean',
        'active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function organizations()
    {
        return $this->hasMany(Organization::class);
    }

    public function features()
    {
        return $this->belongsToMany(Feature::class, 'subscription_plan_feature')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    public function subFeatures()
    {
        return $this->belongsToMany(SubFeature::class, 'subscription_plan_sub_feature')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    public function isFeatureEnabled(int $featureId): bool
    {
        return (bool) $this->features->firstWhere('id', $featureId)?->pivot->enabled;
    }

    public function isSubFeatureEnabled(int $subFeatureId): bool
    {
        return (bool) $this->subFeatures->firstWhere('id', $subFeatureId)?->pivot->enabled;
    }
}
