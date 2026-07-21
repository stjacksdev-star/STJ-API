<?php

namespace App\Http\Controllers\Api;

use App\Models\StorefrontCustomer;
use App\Services\StorefrontRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontRecommendationController extends BaseController
{
    public function __construct(private readonly StorefrontRecommendationService $recommendations) {}

    public function recentlyViewed(Request $request, string $country): JsonResponse { return $this->result($request, $country, 'RECENTLY_VIEWED'); }
    public function product(Request $request, string $country, int $product): JsonResponse { return $this->result($request, $country, 'PDP_RELATED', $product); }
    public function cart(Request $request, string $country): JsonResponse { return $this->result($request, $country, strtoupper((string)$request->query('placement')) === 'ADD_TO_CART_RECOMMENDATIONS' ? 'ADD_TO_CART_RECOMMENDATIONS' : 'CART_RECOMMENDATIONS'); }

    private function result(Request $request, string $country, string $placement, ?int $product = null): JsonResponse
    {
        $visitor = $request->attributes->get('storefrontVisitor');
        $user = Auth::guard('sanctum')->user();
        $customer = $user instanceof StorefrontCustomer ? $user : null;
        return $this->success(['placement'=>$placement,'products'=>$this->recommendations->recommend($country,$placement,$visitor,$customer,$product,(int)$request->integer('limit',10))]);
    }
}
