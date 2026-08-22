<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileCategoryService;
use Illuminate\Http\Request;

class MobileCategoryController extends Controller
{
    public function __construct(private readonly MobileCategoryService $categories) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'records' => $this->categories->all((int) $data['countryId']),
        ]);
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'records' => $this->categories->search((int) $data['countryId']),
        ]);
    }

    public function subcategories(Request $request, int $category, string $type)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'records' => $this->categories->subcategories(
                (int) $data['countryId'],
                $category,
                $type
            ),
        ]);
    }

    public function show(Request $request, int $category)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(
            $this->categories->find((int) $data['countryId'], $category)
        );
    }
}
