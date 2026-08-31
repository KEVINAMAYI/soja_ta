<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sort_order',
    ];

    public function features()
    {
        return $this->hasMany(Feature::class)->orderBy('sort_order');
    }
}
