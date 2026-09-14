<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientIssueUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_issue_id',
        'created_by',
        'status',
        'note',
    ];

    public function issue()
    {
        return $this->belongsTo(ClientIssue::class, 'client_issue_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
