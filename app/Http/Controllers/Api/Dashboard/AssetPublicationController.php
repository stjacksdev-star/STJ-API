<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\AssetPublicationService;
use Illuminate\Http\Request;

class AssetPublicationController extends BaseController
{
    public function __invoke(Request $request, AssetPublicationService $assets)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success($assets->publish(), 'Assets publicados correctamente');
    }
}
