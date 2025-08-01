<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function employeeTypes()
    {
        return $this->hasMany(EmployeeType::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }
}

