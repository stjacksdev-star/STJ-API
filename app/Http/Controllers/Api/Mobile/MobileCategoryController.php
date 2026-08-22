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
}
