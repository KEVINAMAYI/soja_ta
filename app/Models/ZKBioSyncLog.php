<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZKBioSyncLog extends Model
{


    protected $table = 'zkbio_sync_logs';

    protected $fillable = [
        'sync_date',
        'synced_until',
        'synced_at',
    ];

    protected $casts = [
        'sync_date'    => 'date',
        'synced_until' => 'datetime',
        'synced_at'    => 'datetime',
    ];
}
