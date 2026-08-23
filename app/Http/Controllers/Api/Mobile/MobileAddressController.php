<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$customer] = $this->context($request);
        $addresses = $this->addresses($customer);

        return response()->json(['resultado' => true, 'mensaje' => '', 'datos' => $addresses]);
    }

    public function primary(Request $request): JsonResponse
    {
        [$customer, $country] = $this->context($request);
        $records = $this->addresses($customer)
            ->filter(fn (object $address) => $address->dir_principal === 'SI' && $this->sameCountry($address, $country))
            ->values()->all();

        return response()->json(['resultado' => true, 'mensaje' => '', 'datos' => $records, 'records' => $records]);
    }

    public function store(Request $request): JsonResponse
    {
        [$customer, $country] = $this->context($request);
        $data = $request->validate([
            'idUser' => ['nullable'],
            'sameD' => ['nullable', 'string', Rule::in(['SI', 'NO'])],
            'paisEntrega' => ['required', 'string', 'max:100'],
            'departamentoEntrega' => ['required', 'integer'],
            'municipioEntrega' => ['required', 'integer'],
            'departamentoEntregaTxt' => ['nullable', 'string', 'max:150'],
            'municipioEntregaTxt' => ['nullable', 'string', 'max:150'],
            'direccionEntrega' => ['required', 'string', 'min:8', 'max:500'],
            'referencia' => ['nullable', 'string', 'max:500'],
            'personaRecibe' => ['nullable', 'string', 'max:150'],
            'telefonoRecibe' => ['nullable', 'string', 'max:30', 'regex:/^[+ 0-9\/()-]*$/'],
            'tipoDireccion' => ['required', 'string', Rule::in(['CASA', 'TRABAJO', 'OTRO'])],
        ]);

        $requestedCountry = DB::table('stj_world_countries')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $data['paisEntrega']), 'UTF-8')])
            ->first(['id', 'name', 'iso2']);
        if (! $requestedCountry || strtoupper((string) $requestedCountry->iso2) !== strtoupper((string) $country->pai_codigo)) {
            throw ValidationException::withMessages(['paisEntrega' => 'El pais de la direccion no coincide con el pais seleccionado.']);
        }
        $state = DB::table('stj_world_states')->where('id', $data['departamentoEntrega'])
            ->where('country_id', $requestedCountry->id)->first(['id', 'name']);
        $city = $state ? DB::table('stj_world_cities')->where('id', $data['municipioEntrega'])
            ->where('state_id', $state->id)->first(['id', 'name']) : null;
        if (! $state || ! $city) {
            throw ValidationException::withMessages(['municipioEntrega' => 'La ubicacion seleccionada no es valida.']);
        }

        $samePerson = strtoupper((string) ($data['sameD'] ?? 'NO')) === 'SI';
        if (! $samePerson && (trim((string) ($data['personaRecibe'] ?? '')) === '' || trim((string) ($data['telefonoRecibe'] ?? '')) === '')) {
            throw ValidationException::withMessages(['personaRecibe' => 'La persona y telefono que recibiran son requeridos.']);
        }

        $values = [
            'dir_fecha' => now(),
            'dir_usuario' => $customer->getKey(),
            'dir_tipo' => $data['tipoDireccion'],
            'dir_misma_persona' => $samePerson ? 'SI' : 'NO',
            'dir_misma_direccion' => 'NO',
            'dir_pais' => $requestedCountry->name,
            'dir_direccion' => trim((string) $data['direccionEntrega']),
            'dir_referencia' => trim((string) ($data['referencia'] ?? '')),
            'dir_departamento' => $state->id,
            'dir_municipio' => $city->id,
            'dir_departamento_txt' => $state->name,
            'dir_municipio_txt' => $city->name,
            'dir_persona' => $samePerson ? null : trim((string) $data['personaRecibe']),
            'dir_telefono' => $samePerson ? null : trim((string) $data['telefonoRecibe']),
            'dir_save' => 'SI',
            'dir_principal' => 'SI',
        ];
        foreach (['dir_latitud' => 0, 'dir_longitud' => 0, 'dir_distrito' => null] as $column => $value) {
            if (Schema::hasColumn('stj_direcciones', $column)) {
                $values[$column] = $value;
            }
        }

        DB::transaction(function () use ($customer, $values): void {
            DB::table('stj_direcciones')->where('dir_usuario', $customer->getKey())->update(['dir_principal' => 'NO']);
            DB::table('stj_direcciones')->insert($values);
        });

        return response()->json(['resultado' => 'true', 'mensaje' => 'exito.']);
    }

    public function makePrimary(Request $request, int $address): JsonResponse
    {
        [$customer, $country] = $this->context($request);
        $owned = DB::table('stj_direcciones')->where('dir_id', $address)
            ->where('dir_usuario', $customer->getKey())->where('dir_save', 'SI')->first();
        if (! $owned || ! $this->sameCountry($owned, $country)) {
            return response()->json(['resultado' => false, 'mensaje' => 'Direccion no encontrada.', 'datos' => null], 404);
        }

        DB::transaction(function () use ($customer, $address): void {
            DB::table('stj_direcciones')->where('dir_usuario', $customer->getKey())->update(['dir_principal' => 'NO']);
            DB::table('stj_direcciones')->where('dir_id', $address)->where('dir_usuario', $customer->getKey())
                ->update(['dir_principal' => 'SI']);
        });

        return response()->json(['resultado' => true, 'mensaje' => '', 'datos' => null]);
    }

    private function context(Request $request): array
    {
        $customer = $request->user();
        if (! $customer instanceof StorefrontCustomer || ! $customer->tokenCan('mobile:account')) {
            abort(403, 'Sesion mobile no valida.');
        }
        $data = $request->validate(['countryId' => ['required', 'integer', 'min:1']]);
        $country = DB::table('stj_paises')->where('pai_id', (int) $data['countryId'])->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        return [$customer, $country];
    }

    private function addresses(StorefrontCustomer $customer)
    {
        return DB::table('stj_direcciones')->where('dir_usuario', $customer->getKey())->where('dir_save', 'SI')
            ->orderByRaw("dir_principal = 'SI' DESC")->orderByDesc('dir_id')->get();
    }

    private function sameCountry(object $address, object $country): bool
    {
        $iso2 = DB::table('stj_world_countries')->where('name', $address->dir_pais)->value('iso2');

        return strtoupper((string) $iso2) === strtoupper((string) $country->pai_codigo);
    }
}
