<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $fillable = [
        'feature_category_id',
        'name',
        'slug',
        'description',
        'sort_order',
    ];

    public function category()
    {
        return $this->belongsTo(FeatureCategory::class, 'feature_category_id');
    }

    public function subFeatures()
    {
        return $this->hasMany(SubFeature::class)->orderBy('sort_order');
    }

    public function subscriptionPlans()
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'subscription_plan_feature')
            ->withPivot('enabled')
            ->withTimestamps();
    }
}
