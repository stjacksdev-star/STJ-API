<?php

namespace App\Http\Controllers\Api;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontFavoriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StorefrontFavoriteController extends BaseController
{
    public function __construct(private readonly StorefrontFavoriteService $favorites) {}

    public function index(Request $request, string $country)
    {
        [$visitor, $customer] = $this->identity($request);
        return $this->success($this->favorites->list($country, $visitor, $customer));
    }

    public function store(Request $request, string $country)
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'origin' => ['nullable', Rule::in(['WEB', 'ANDROID', 'IOS'])]]);
        [$visitor, $customer] = $this->identity($request);
        return $this->success($this->favorites->add($country, (int) $data['product_id'], $visitor, $customer, $data['origin'] ?? 'WEB'), 'Agregado a favoritos');
    }

    public function destroy(Request $request, string $country, int $product)
    {
        [$visitor, $customer] = $this->identity($request);
        return $this->success($this->favorites->remove($country, $product, $visitor, $customer), 'Eliminado de favoritos');
    }

    private function identity(Request $request): array
    {
        $visitor = $this->visitor($request);
        $customer = $this->customer();
        if ($customer) $this->favorites->merge($visitor, $customer);

        return [$visitor, $customer];
    }

    private function visitor(Request $request): StorefrontVisitor { return $request->attributes->get('storefrontVisitor'); }
    private function customer(): ?StorefrontCustomer
    {
        // These routes deliberately remain public for guests. Resolve Sanctum
        // explicitly so an optional customer bearer is still authenticated.
        $user = Auth::guard('sanctum')->user();

        return $user instanceof StorefrontCustomer ? $user : null;
    }
}
