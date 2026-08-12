<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushAutomation extends Model
{
    protected $table = 'stj_push_automatizaciones';

    protected $primaryKey = 'pau_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'pau_paises' => 'array',
        'pau_configuracion' => 'array',
        'pau_creado_en' => 'datetime',
        'pau_actualizado_en' => 'datetime',
    ];
}
