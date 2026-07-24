<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontBestSellerRankingService;
use Illuminate\Http\Request;

class StorefrontBestSellerController extends BaseController
{
    public function __construct(
        private readonly StorefrontBestSellerRankingService $rankings,
    ) {
    }

    public function index(Request $request, string $country)
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'integer', 'in:7,14,30'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return $this->success(
            $this->rankings->paginate(
                $country,
                (int) ($validated['period'] ?? 30),
                (int) ($validated['per_page'] ?? 15),
            ),
            'Ranking de productos más vendidos obtenido',
        );
    }
}
