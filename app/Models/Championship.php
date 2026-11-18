<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Championship extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'image',
    ];

    public function leagues(): HasMany
    {
        return $this->hasMany(League::class);
    }
}
