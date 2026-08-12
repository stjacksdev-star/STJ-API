<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebPushDelivery;
use App\Services\WebPushMeasurementService;
use Illuminate\Http\RedirectResponse;

class WebPushClickController extends Controller
{
    public function __invoke(WebPushDelivery $delivery, WebPushMeasurementService $measurements): RedirectResponse
    {
        $measurements->recordClick($delivery);

        $destination = trim((string) $delivery->pen_action);
        if (! filter_var($destination, FILTER_VALIDATE_URL) || ! in_array(parse_url($destination, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $destination = (string) config('services.fcm.web_home_url');
        }

        return redirect()->away($destination, 302, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
