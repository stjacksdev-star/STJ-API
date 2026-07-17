<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class StorefrontCustomer extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'stj_usuarios';

    protected $primaryKey = 'usu_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'usu_password',
        'usu_url_token',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->usu_password;
    }
}
