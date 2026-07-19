<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontCartItem extends Model
{
    protected $table = 'stj_carrito_detalles';
    protected $primaryKey = 'cad_id';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['cad_seleccionado' => 'boolean'];
}
