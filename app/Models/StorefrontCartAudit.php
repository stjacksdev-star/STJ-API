<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontCartAudit extends Model
{
    protected $table = 'stj_carrito_auditoria';
    protected $primaryKey = 'cau_id';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['cau_datos_anteriores' => 'array', 'cau_datos_nuevos' => 'array'];
}
