<?php

namespace App\Models;

use App\Helpers\QRCodeGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'department_id',
        'user_id',
        'name',
        'id_number',
        'email',
        'phone',
        'status',
        'face_id',
        'shift_id',
    ];


    protected static function booted()
    {
        static::creating(function ($employee) {
            if (!$employee->qr_code) {
                $employee->qr_code = QRCodeGenerator::generateEmployeeCode(
                    $employee->organization_id,
                    $employee->id ?? (Employee::max('id') + 1)
                );
            }
        });
    }


    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function employeeType()
    {
        return $this->belongsTo(EmployeeType::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function overtimes()
    {
        return $this->hasMany(Overtime::class);
    }

    public function serviceUsages()
    {
        return $this->hasMany(ServiceUsage::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

}

