<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\BaseController;
use App\Models\StorefrontCustomer;
use App\Services\Mobile\MobileVisitorService;
use App\Services\StorefrontDailyVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileDailyVisitController extends BaseController
{
    public function __invoke(
        Request $request,
        MobileVisitorService $mobileVisitors,
        StorefrontDailyVisitService $dailyVisits,
    ): JsonResponse {
        $data = $request->validate([
            'installation_uuid' => ['required', 'uuid'],
            'countryId' => ['required', 'integer', 'min:1'],
            'platform' => ['required', 'string', Rule::in(['IOS', 'ANDROID'])],
        ]);

        $countryId = (int) $data['countryId'];
        $countryCode = DB::table('stj_paises')
            ->where('pai_id', $countryId)
            ->value('pai_codigo');

        if ($countryCode === null) {
            throw ValidationException::withMessages([
                'countryId' => 'Pais no soportado.',
            ]);
        }

        $platform = strtoupper((string) $data['platform']);
        $visitor = $mobileVisitors->resolve(
            (string) $data['installation_uuid'],
            $countryId,
            $platform,
        );
        $authenticated = Auth::guard('sanctum')->user();
        $customer = $authenticated instanceof StorefrontCustomer
            && $authenticated->tokenCan('mobile:account')
                ? $authenticated
                : null;
        $result = $dailyVisits->record(
            (string) $countryCode,
            $visitor,
            $customer,
            'APP-'.$platform,
        );

        return response()->json([
            'ok' => true,
            'message' => $result['created'] ? 'Visita registrada.' : 'Visita registrada previamente.',
            'data' => $result,
        ], $result['created'] ? 201 : 200);
    }
}
