<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagementPlatformSalesReportService
{
    public function report(string $country, string $startDate, string $endDate): array
    {
        [$countryId, $countryData, $start, $end] = $this->filters($country, $startDate, $endDate);
        $orders = $this->baseQuery($countryId, $start, $end)->get();
        $rows = [];
        $indicators = [];

        foreach ($orders as $order) {
            $platform = $this->platform($order);
            $type = $this->type((string) $order->ped_checkout);
            $key = $platform.'|'.$type;
            $rows[$key] ??= ['platform' => $platform, 'origin' => $platform === 'WEB' ? 'WEB' : 'APP', 'type' => $type, 'orders' => 0, 'amount' => 0.0];
            $rows[$key]['orders']++;
            $rows[$key]['amount'] = round($rows[$key]['amount'] + (float) $order->ppa_monto_senv, 2);
            $indicators[$platform] ??= ['platform' => $platform, 'orders' => 0, 'amount' => 0.0];
            $indicators[$platform]['orders']++;
            $indicators[$platform]['amount'] = round($indicators[$platform]['amount'] + (float) $order->ppa_monto_senv, 2);
        }

        $indicators = collect($indicators)->sortByDesc('amount')->values()->all();

        return [
            'countries' => $this->countries(),
            'filters' => ['country' => $countryData, 'startDate' => $start, 'endDate' => $end],
            'indicators' => $indicators,
            'winner' => $indicators[0] ?? null,
            'rows' => collect($rows)->sortBy([['amount', 'desc']])->values()->all(),
            'totals' => ['orders' => $orders->count(), 'amount' => round($orders->sum('ppa_monto_senv'), 2)],
        ];
    }

    public function orders(string $country, string $startDate, string $endDate, string $platform, string $type): array
    {
        [$countryId, $countryData, $start, $end] = $this->filters($country, $startDate, $endDate);
        $platform = strtoupper($platform);
        $type = strtoupper($type);
        $orders = $this->platformQuery($this->baseQuery($countryId, $start, $end), $platform)
            ->get()
            ->filter(fn (object $order) => $this->type((string) $order->ped_checkout) === $type)
            ->map(fn (object $order) => [
                'reference' => (string) $order->ppa_ref,
                'date' => (string) $order->ppa_fecha,
                'customer' => trim((string) $order->ped_nombres.' '.(string) $order->ped_apellidos),
                'status' => (string) $order->ped_estatus,
                'origin' => (string) $order->ped_origen,
                'platform' => $this->platform($order),
                'type' => $this->type((string) $order->ped_checkout),
                'amount' => round((float) $order->ppa_monto_senv, 2),
            ])->values()->all();

        return [
            'filters' => ['country' => $countryData, 'startDate' => $start, 'endDate' => $end, 'platform' => $platform, 'type' => $type],
            'summary' => ['orders' => count($orders), 'amount' => round(array_sum(array_column($orders, 'amount')), 2)],
            'orders' => $orders,
        ];
    }

    private function baseQuery(int $countryId, string $start, string $end): Builder
    {
        return DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payments', 'payments.ppa_pedido', '=', 'orders.ped_id')
            ->where('payments.ppa_estado', 'APROBADA')
            ->where('orders.ped_id_pais', $countryId)
            ->whereBetween('payments.ppa_fecha', [$start.' 00:00:00', $end.' 23:59:59'])
            ->orderByDesc('payments.ppa_fecha')
            ->select([
                'orders.ped_origen', 'orders.ped_plataforma', 'orders.ped_checkout', 'orders.ped_estatus',
                'orders.ped_nombres', 'orders.ped_apellidos', 'payments.ppa_ref', 'payments.ppa_fecha', 'payments.ppa_monto_senv',
            ]);
    }

    private function platformQuery(Builder $query, string $platform): Builder
    {
        return match ($platform) {
            'WEB' => $query->where('orders.ped_origen', 'WEB'),
            'APP-IOS' => $query->where('orders.ped_origen', 'APP')->whereRaw('UPPER(orders.ped_plataforma) = ?', ['IOS']),
            'APP-ANDROID' => $query->where('orders.ped_origen', 'APP')->whereRaw('UPPER(orders.ped_plataforma) = ?', ['ANDROID']),
            'APP-SIN-PLATAFORMA' => $query->where('orders.ped_origen', 'APP')->where(function ($query) {
                $query->whereNull('orders.ped_plataforma')
                    ->orWhere('orders.ped_plataforma', '')
                    ->orWhereNotIn(DB::raw('UPPER(orders.ped_plataforma)'), ['IOS', 'ANDROID']);
            }),
            default => throw ValidationException::withMessages(['platform' => 'La plataforma seleccionada no es valida.']),
        };
    }

    private function platform(object $order): string
    {
        if (strtoupper(trim((string) $order->ped_origen)) !== 'APP') {
            return 'WEB';
        }
        $platform = strtoupper(trim((string) $order->ped_plataforma));

        return in_array($platform, ['IOS', 'ANDROID'], true) ? 'APP-'.$platform : 'APP-SIN-PLATAFORMA';
    }

    private function type(string $checkout): string
    {
        return in_array(strtoupper(trim($checkout)), ['D', 'DOMICILIO'], true) ? 'DOMICILIO' : 'TIENDA';
    }

    private function filters(string $country, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->toDateString();
        $end = Carbon::parse($endDate)->toDateString();
        if ($start > $end) {
            throw ValidationException::withMessages(['endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.']);
        }
        $countryRow = DB::table('stj_paises')->where(function ($query) use ($country) {
            $query->where('pai_codigo', strtoupper($country));
            if (ctype_digit($country)) {
                $query->orWhere('pai_id', (int) $country);
            }
        })->first();
        if (! $countryRow) {
            throw ValidationException::withMessages(['country' => 'El pais seleccionado no existe.']);
        }

        return [(int) $countryRow->pai_id, ['id' => (int) $countryRow->pai_id, 'code' => (string) $countryRow->pai_codigo, 'name' => (string) $countryRow->pai_nombre], $start, $end];
    }

    private function countries(): array
    {
        return DB::table('stj_paises')->orderBy('pai_nombre')->get(['pai_id', 'pai_codigo', 'pai_nombre'])->map(fn ($country) => ['id' => (int) $country->pai_id, 'code' => (string) $country->pai_codigo, 'name' => (string) $country->pai_nombre])->all();
    }
}
