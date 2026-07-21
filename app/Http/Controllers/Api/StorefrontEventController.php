<?php

namespace App\Http\Controllers\Api;

use App\Models\CustomerEvent;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorefrontEventController extends BaseController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_uuid' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['PRODUCT_VIEW', 'RECOMMENDATION_VIEW', 'RECOMMENDATION_IMPRESSION', 'RECOMMENDATION_CLICK'])],
            'country' => ['required', 'string', 'size:2'],
            'product_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'metadata' => ['sometimes', 'array'],
            'metadata.slug' => ['sometimes', 'string', 'max:255'],
            'metadata.sku' => ['sometimes', 'string', 'max:50'],
            'metadata.placement' => ['sometimes', Rule::in(['PDP_RELATED', 'CART_RECOMMENDATIONS', 'ADD_TO_CART_RECOMMENDATIONS', 'ADD_TO_CART_DRAWER', 'RECENTLY_VIEWED'])],
            'metadata.recommendation_reason' => ['sometimes', Rule::in(['SAME_COLLECTION', 'SAME_CATEGORY', 'SAME_BRAND', 'SIMILAR_PRICE', 'POPULAR', 'RECENTLY_VIEWED'])],
            'metadata.position' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        /** @var StorefrontVisitor|null $visitor */
        $visitor = $request->attributes->get('storefrontVisitor');

        if (! $visitor) {
            return $this->error('No fue posible resolver la identidad del visitante.', 500);
        }

        $countryId = DB::table('stj_paises')
            ->where('pai_codigo', strtoupper($data['country']))
            ->value('pai_id');

        if ($countryId === null || ! DB::table('stj_productos')->where('pro_id', $data['product_id'])->exists()) {
            return $this->error('El pais o producto indicado no existe.', 422);
        }

        $existing = CustomerEvent::query()->where('cev_event_uuid', $data['event_uuid'])->first();

        if ($existing) {
            return $this->eventResponse($existing, false);
        }

        $authenticated = Auth::guard('sanctum')->user();
        $customerId = $authenticated instanceof StorefrontCustomer ? (int) $authenticated->getKey() : null;

        try {
            $event = CustomerEvent::query()->create([
                'cev_event_uuid' => strtolower($data['event_uuid']),
                'cev_visitante_id' => $visitor->getKey(),
                'cev_usu_id' => $customerId,
                'cev_pais_id' => (int) $countryId,
                'cev_producto_id' => (int) $data['product_id'],
                'cev_tipo' => $data['type'],
                'cev_origen' => 'WEB',
                'cev_ocurrido_en' => $data['occurred_at'],
                'cev_recibido_en' => now(),
                'cev_metadata' => array_intersect_key($data['metadata'] ?? [], array_flip(['slug', 'sku', 'placement', 'recommendation_reason', 'position'])),
            ]);
        } catch (QueryException $exception) {
            if (! in_array((string) ($exception->errorInfo[1] ?? ''), ['1062', '19'], true)) {
                throw $exception;
            }

            $event = CustomerEvent::query()->where('cev_event_uuid', $data['event_uuid'])->firstOrFail();

            return $this->eventResponse($event, false);
        }

        return $this->eventResponse($event, true);
    }

    private function eventResponse(CustomerEvent $event, bool $created): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => $created ? 'Evento registrado.' : 'Evento registrado previamente.',
            'data' => [
                'event_uuid' => $event->cev_event_uuid,
                'type' => $event->cev_tipo,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }
}
