<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubFeature extends Model
{
    protected $fillable = [
        'feature_id',
        'name',
        'slug',
        'description',
        'sort_order',
    ];

    public function feature()
    {
        return $this->belongsTo(Feature::class);
    }

    public function subscriptionPlans()
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'subscription_plan_sub_feature')
            ->withPivot('enabled')
            ->withTimestamps();
    }
}
