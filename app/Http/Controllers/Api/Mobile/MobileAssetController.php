<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileLifestyleAssetService;
use Illuminate\Http\Request;

class MobileAssetController extends Controller
{
    public function lifestyle(Request $request, MobileLifestyleAssetService $assets)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'exists:stj_paises,pai_id'],
        ]);

        return response()->json($assets->forCountry((int) $data['countryId']));
    }
}
