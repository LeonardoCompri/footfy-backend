<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicalCommitteeMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'role',
        'birth_date',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
