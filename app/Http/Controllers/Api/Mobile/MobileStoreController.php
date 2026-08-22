<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileStoreService;
use Illuminate\Http\Request;

class MobileStoreController extends Controller
{
    public function __construct(private readonly MobileStoreService $stores) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'records' => $this->stores->forCountry((int) $data['countryId']),
        ]);
    }
}
