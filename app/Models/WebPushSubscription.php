<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    protected $table = 'stj_push_suscripciones';

    protected $primaryKey = 'psu_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['psu_token', 'psu_token_hash'];

    protected $casts = [
        'psu_ultima_actividad_en' => 'datetime',
        'psu_token_actualizado_en' => 'datetime',
        'psu_revocado_en' => 'datetime',
        'psu_creado_en' => 'datetime',
        'psu_actualizado_en' => 'datetime',
    ];
}
