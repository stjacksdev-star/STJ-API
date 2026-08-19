<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontContactService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Throwable;

class StorefrontContactController extends BaseController
{
    public function __construct(private readonly StorefrontContactService $contacts) {}

    public function store(Request $request, string $country)
    {
        $country = strtoupper($country);
        if (! DB::table('stj_paises')->where('pai_codigo', $country)->exists()) {
            return $this->error('El pais del storefront no es valido.', 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone_country' => ['required', 'string', 'regex:/^\+[0-9]{1,4}$/'],
            'phone' => ['required', 'string', 'min:7', 'max:20', 'regex:/^[0-9 ()-]+$/'],
            'topic' => ['required', Rule::in(['order', 'product', 'store', 'website', 'other'])],
            'message' => ['required', 'string', 'min:20', 'max:1500'],
            'privacy_accepted' => ['accepted'],
            'website' => ['nullable', 'string', 'max:0'],
            'started_at' => ['required', 'integer'],
        ]);

        if ((int) $data['started_at'] > now()->subSeconds(2)->getTimestamp() || (int) $data['started_at'] < now()->subHours(2)->getTimestamp()) {
            return $this->error('No pudimos validar el formulario. Recarga la pagina e intenta nuevamente.', 422);
        }

        $key = 'storefront-contact:'.hash('sha256', ($request->ip() ?? '').'|'.strtolower($data['email']));
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->error('Has enviado varias solicitudes. Espera un momento antes de intentarlo nuevamente.', 429);
        }
        RateLimiter::hit($key, 3600);

        try {
            $reference = $this->contacts->send($data, $country);
            Log::info('Formulario de contacto del storefront enviado.', ['reference' => $reference, 'country' => $country]);
        } catch (Throwable $exception) {
            Log::error('No fue posible enviar el formulario de contacto del storefront.', ['country' => $country, 'exception' => $exception->getMessage()]);
            return $this->error('No pudimos enviar tu mensaje en este momento. Intenta nuevamente mas tarde.', 503);
        }

        return $this->success(['reference' => $reference], 'Recibimos tu mensaje. Nuestro equipo se pondra en contacto contigo.');
    }
}
