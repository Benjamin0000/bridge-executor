<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    protected $fillable = [
        'nouns',
        'depositor',
        'from_token_address',
        'to_token_address', 
        'token_from', 
        'token_to',
        'pool_address',
        'to',
        'amount_in',
        'amount_out',
        'timestamp',
        'source_chain',
        'destination_chain',
        'status',
        'tx_hash',
        'release_tx_hash',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}