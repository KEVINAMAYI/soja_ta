<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'organization_id',
        'description',
        'manager_id',
    ];


    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

}
