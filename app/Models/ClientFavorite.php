<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientFavorite extends Model
{
    protected $table = 'client_favorites';

    protected $fillable = [
        'client_id',
        'performer_transport_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
