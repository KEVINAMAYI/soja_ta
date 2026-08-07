<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'organization_id'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }
}
