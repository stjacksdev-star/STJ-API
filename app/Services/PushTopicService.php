<?php

namespace App\Services;

use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PushTopicService
{
    public function syncAutomatic(WebPushSubscription $subscription): void
    {
        $countryCode = strtolower((string) DB::table('stj_paises')->where('pai_id', $subscription->psu_pais_id)->value('pai_codigo'));
        $codes = array_filter([
            'platform.'.strtolower((string) $subscription->psu_plataforma),
            $countryCode !== '' ? 'country.'.$countryCode : null,
            $subscription->psu_usu_id ? 'customer.registered' : 'customer.guest',
        ]);

        $topicIds = [];
        foreach ($codes as $code) {
            $topicId = DB::table('stj_push_topics')->where('pto_codigo', $code)->where('pto_estado', 'ACTIVO')->value('pto_id');
            if (! $topicId) {
                throw ValidationException::withMessages(['topics' => "No existe el topic automatico {$code}."]);
            }
            $topicIds[] = (int) $topicId;
            DB::table('stj_push_suscripcion_topics')->updateOrInsert(
                ['pst_suscripcion_id' => $subscription->getKey(), 'pst_topic_id' => $topicId],
                ['pst_origen' => 'AUTOMATICO', 'pst_suscrito_en' => now(), 'pst_expira_en' => null],
            );
        }

        DB::table('stj_push_suscripcion_topics')
            ->where('pst_suscripcion_id', $subscription->getKey())
            ->where('pst_origen', 'AUTOMATICO')
            ->whereNotIn('pst_topic_id', $topicIds)
            ->delete();
    }
}
