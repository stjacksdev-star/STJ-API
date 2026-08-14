<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\CouponService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends BaseController
{
    public function __construct(private readonly CouponService $coupons) {}

    public function index(Request $request)
    {
        abort_unless($request->user()?->tokenCan('dashboard'), 403);
        return $this->success($this->coupons->index(
            $request->string('country')->toString() ?: null,
            $request->string('status')->toString() ?: null,
            $request->string('search')->toString() ?: null,
            $request->integer('page', 1),
            $request->integer('perPage', 20),
        ));
    }
    public function catalogs(Request $request) { abort_unless($request->user()?->tokenCan('dashboard'), 403); return $this->success($this->coupons->catalogs($request->string('country')->toString())); }

    public function store(Request $request) { return $this->persist($request); }
    public function update(Request $request, int $coupon) { return $this->persist($request, $coupon); }

    private function persist(Request $request, ?int $coupon = null)
    {
        abort_unless($request->user()?->tokenCan('dashboard'), 403);
        $data = $request->validate([
            'country' => ['required', 'string', 'max:3'], 'name' => ['required', 'string', 'max:100'],
            'commercialName' => ['nullable', 'string', 'max:250'], 'channel' => ['required', Rule::in(['TODO', 'WEB'])],
            'type' => ['required', Rule::in(['PRECIO', 'DESCUENTO', 'ENVIO_GRATIS'])],
            'checkout' => ['required', Rule::in(['TODO', 'DOMICILIO', 'TIENDA'])],
            'generic' => ['required', Rule::in(['SI', 'NO'])], 'code' => ['nullable', 'required_if:generic,SI', 'string', 'max:100'],
            'amount' => ['nullable', 'required_if:type,PRECIO', 'numeric', 'min:0.01'],
            'discount' => ['nullable', 'required_if:type,DESCUENTO', 'numeric', 'min:0.01', 'lt:100'],
            'minimumEnabled' => ['required', Rule::in(['SI', 'NO'])], 'minimumAmount' => ['nullable', 'required_if:minimumEnabled,SI', 'numeric', 'min:0'],
            'extraDiscount' => ['required', Rule::in(['SI', 'NO'])], 'multiple' => ['required', Rule::in(['SI', 'NO'])],
            'promotionRule' => ['required', Rule::in(['TODOS', 'REGULAR', 'PROMO'])],
            'firstPurchaseOnly' => ['required', Rule::in(['SI', 'NO'])], 'startAt' => ['required', 'date'],
            'endAt' => ['required', 'date', 'after:startAt'], 'status' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
            'audience' => ['required', Rule::in(['NA', 'VIP', 'PLA'])],
            'productScope' => ['required', Rule::in(['NA', 'PLA', 'GEN', 'COL'])],
            'categoryId' => ['nullable', 'required_if:productScope,GEN', 'integer'],
            'collectionId' => ['nullable', 'required_if:productScope,COL', 'integer'],
            'automaticTemplate' => ['nullable', 'string', 'max:50'],
            'productsFile' => ['nullable', ...($coupon ? [] : ['required_if:productScope,PLA']), 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
            'customersFile' => ['nullable', ...($coupon ? [] : ['required_if:audience,PLA']), 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
        ]);
        return $this->success($this->coupons->save($data, $coupon, $request->file('productsFile'), $request->file('customersFile')), $coupon ? 'Cupón actualizado.' : 'Cupón creado.');
    }
}
