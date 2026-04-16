<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidoController extends BaseController
{
    public function getPedidoById(Request $request)
    {
        $request->validate([
            'ped_id' => 'required|integer',
            'ppa_id' => 'required|integer',
            'ped_id_pais' => 'required|integer',
        ]);

        $pedido = $request->ped_id;
        $pago = $request->ppa_id;
        $idPais = $request->ped_id_pais;

        $datos = DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_direccion as pd', 'p.ped_id', '=', 'pd.pdi_pedido')
            ->leftJoin('stj_direcciones as d', 'pd.pdi_direccion', '=', 'd.dir_id')
            ->leftJoin('stj_pedidos_tienda as pt', 'p.ped_id', '=', 'pt.pti_pedido')
            ->leftJoin('stj_tiendas as t', function ($join) use ($idPais) {
                $join->on('pt.pti_tienda', '=', 't.tie_codigo')
                     ->where('t.tie_pais', '=', $idPais);
            })
            ->leftJoin('stj_pedidos_pago as pp', function ($join) use ($pago) {
                $join->on('pp.ppa_pedido', '=', 'p.ped_id')
                     ->where('pp.ppa_id', '=', $pago);
            })
            ->leftJoin('stj_mensajes_fac as mf', function ($join) {
                $join->on('mf.mfa_tarjeta', '=', 'pp.ppa_emisor')
                     ->on('mf.mfa_codigo', '=', 'pp.ppa_rsp_codigo');
            })
            ->where('p.ped_id', $pedido)
            ->select(
                'p.*',
                'pd.*',
                'd.*',
                'pt.*',
                't.*',
                'pp.*',
                'mf.*'
            )
            ->first();

        if (!$datos) {
            return $this->error('Pedido no encontrado', 404);
        }

        return $this->success($datos, 'Detalle de pedido obtenido');
    }
}