<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushEvent extends Model
{
    protected $table = 'stj_push_eventos';

    protected $primaryKey = 'pev_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'pev_datos' => 'array',
        'pev_ocurrido_en' => 'datetime',
        'pev_recibido_en' => 'datetime',
    ];
}
