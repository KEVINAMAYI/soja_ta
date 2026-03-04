<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZkbioProcessedLog extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'log_id';
    public $incrementing = false;

    protected $fillable = [
        'log_id',
        'device_sn',
        'pin',
        'organization_id',
        'processed_at',
    ];

    protected $casts = [
        'log_id'          => 'integer',
        'organization_id' => 'integer',
        'processed_at'    => 'datetime',
    ];
}
