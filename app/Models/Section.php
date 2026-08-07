<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'department_id', 'organization_id'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function subsections()
    {
        return $this->hasMany(Subsection::class);
    }
}
