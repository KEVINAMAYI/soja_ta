<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subsection extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'section_id', 'organization_id'];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }
}
