<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushDelivery extends Model
{
    protected $table = 'stj_push_entregas';

    protected $primaryKey = 'pen_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'pen_payload' => 'array',
        'pen_programado_en' => 'datetime',
        'pen_disponible_en' => 'datetime',
        'pen_bloqueado_en' => 'datetime',
        'pen_ultimo_intento_en' => 'datetime',
        'pen_enviado_en' => 'datetime',
        'pen_creado_en' => 'datetime',
        'pen_actualizado_en' => 'datetime',
    ];
}
