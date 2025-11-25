<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lp extends Model
{
    protected $fillable = [
        'wallet_address',
        'amount',
        'profit',
        'active',
        'network'
    ];

    protected $casts = [
        'amount' => 'float',
        'profit' => 'float',
        'active' => 'boolean',
        'hashes' => 'array',
    ];
}
