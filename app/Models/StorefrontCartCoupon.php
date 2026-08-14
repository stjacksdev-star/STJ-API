<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontCartCoupon extends Model
{
    protected $table = 'stj_carrito_cupones';

    protected $primaryKey = 'ccu_id';

    public $timestamps = false;

    protected $guarded = [];
}
