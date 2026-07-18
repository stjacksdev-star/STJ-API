<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontVisitor extends Model
{
    protected $table = 'stj_visitantes';

    protected $primaryKey = 'vis_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'vis_primera_visita' => 'datetime',
        'vis_ultima_visita' => 'datetime',
        'vis_expira_en' => 'datetime',
        'vis_creado_en' => 'datetime',
        'vis_actualizado_en' => 'datetime',
    ];
}
