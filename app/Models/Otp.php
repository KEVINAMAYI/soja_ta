<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Otp extends Model
{
    use HasFactory;

    protected $fillable = ['phone', 'otp', 'expires_at'];

    public $timestamps = true;

    public function isExpired(): bool
    {
        return Carbon::now()->greaterThan($this->expires_at);
    }
}
