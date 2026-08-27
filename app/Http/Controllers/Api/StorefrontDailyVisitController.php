<?php

namespace App\Http\Controllers\Api;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontDailyVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontDailyVisitController extends BaseController
{
    public function __invoke(
        Request $request,
        string $country,
        StorefrontDailyVisitService $visits,
    ): JsonResponse {
        $visitor = $request->attributes->get('storefrontVisitor');

        if (! $visitor instanceof StorefrontVisitor) {
            return $this->error('No fue posible resolver la identidad del visitante.', 500);
        }

        $authenticated = Auth::guard('sanctum')->user();
        $customer = $authenticated instanceof StorefrontCustomer ? $authenticated : null;
        $result = $visits->record($country, $visitor, $customer);

        return response()->json([
            'ok' => true,
            'message' => $result['created'] ? 'Visita registrada.' : 'Visita registrada previamente.',
            'data' => $result,
        ], $result['created'] ? 201 : 200);
    }
}
