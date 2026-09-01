<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerAccountDeletionService
{
    public function delete(StorefrontCustomer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $lockedCustomer = StorefrontCustomer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if (! (bool) $lockedCustomer->usu_activo) return;

            $originalUsername = trim((string) $lockedCustomer->usu_usuario);
            $suffix = '_deleted';
            $sequence = 1;
            do {
                $numberedSuffix = $sequence === 1 ? $suffix : $suffix.'_'.$sequence;
                $candidate = mb_substr($originalUsername, 0, 100 - mb_strlen($numberedSuffix)).$numberedSuffix;
                $exists = StorefrontCustomer::query()
                    ->where('usu_usuario', $candidate)
                    ->where($lockedCustomer->getKeyName(), '<>', $lockedCustomer->getKey())
                    ->exists();
                $sequence++;
            } while ($exists);

            $values = ['usu_usuario' => $candidate, 'usu_activo' => 0];
            if (Schema::hasColumn('stj_usuarios', 'usu_ultima_modificacion')) {
                $values['usu_ultima_modificacion'] = now();
            }
            $lockedCustomer->forceFill($values)->save();

            if (Schema::hasTable('stj_storefront_password_resets')) {
                DB::table('stj_storefront_password_resets')
                    ->where('spr_email', strtolower($originalUsername))
                    ->delete();
            }
            if (Schema::hasTable('stj_push_suscripciones')) {
                WebPushSubscription::query()
                    ->where('psu_usu_id', $lockedCustomer->getKey())
                    ->update([
                        'psu_estado' => 'REVOCADA',
                        'psu_permiso' => 'DENIED',
                        'psu_revocado_en' => now(),
                        'psu_ultima_actividad_en' => now(),
                        'psu_actualizado_en' => now(),
                    ]);
            }
            $lockedCustomer->tokens()->delete();
        });
    }
}
