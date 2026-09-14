<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'created_by',
        'reference',
        'subject',
        'description',
        'status',
        'priority',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updates()
    {
        return $this->hasMany(ClientIssueUpdate::class)->latest();
    }
}
