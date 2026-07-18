<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerEvent extends Model
{
    protected $table = 'stj_cliente_eventos';

    protected $primaryKey = 'cev_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'cev_metadata' => 'array',
        'cev_ocurrido_en' => 'datetime',
        'cev_recibido_en' => 'datetime',
    ];
}
