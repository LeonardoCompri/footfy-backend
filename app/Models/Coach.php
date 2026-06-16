<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coach extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'birth_date',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
