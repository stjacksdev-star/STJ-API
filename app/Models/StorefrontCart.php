<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontCart extends Model
{
    protected $table = 'stj_carritos';
    protected $primaryKey = 'car_id';
    public $timestamps = false;
    protected $guarded = [];

    public function items() { return $this->hasMany(StorefrontCartItem::class, 'cad_carrito_id', 'car_id'); }
}
