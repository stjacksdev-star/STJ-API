<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class VipCustomerService
{
    /** @return array{qualified:int, reset:int, marked:int, since:string} */
    public function refresh(): array
    {
        $since = now()->subMonthsNoOverflow(6)->toDateTimeString();
        $qualifiedQuery = DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payments', 'payments.ppa_pedido', '=', 'orders.ped_id')
            ->where('payments.ppa_estado', 'APROBADA')
            ->where('payments.ppa_fecha', '>=', $since)
            ->whereNotNull('orders.ped_user')
            ->select('orders.ped_user')
            ->groupBy('orders.ped_user')
            ->havingRaw('COUNT(DISTINCT orders.ped_id) >= 3');
        $qualified = DB::query()
            ->fromSub(clone $qualifiedQuery, 'qualified_vip_customers')
            ->count();

        return DB::transaction(function () use ($qualifiedQuery, $qualified, $since): array {
            $reset = DB::table('stj_usuarios')->update(['usu_vip' => 'NO']);
            $marked = DB::table('stj_usuarios')
                ->whereIn('usu_id', $qualifiedQuery)
                ->update(['usu_vip' => 'SI']);

            return [
                'qualified' => $qualified,
                'reset' => $reset,
                'marked' => $marked,
                'since' => $since,
            ];
        });
    }
}
