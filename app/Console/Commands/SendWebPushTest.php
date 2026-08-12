<?php

namespace App\Console\Commands;

use App\Models\WebPushSubscription;
use App\Services\WebPushSubscriptionService;
use Illuminate\Console\Command;
use Throwable;

class SendWebPushTest extends Command
{
    protected $signature = 'push:web-test
        {subscription : ID de stj_push_suscripciones}
        {--title=ST Jacks : Titulo}
        {--body=Las notificaciones web estan funcionando correctamente. : Mensaje}
        {--action= : URL que abrira la notificacion}
        {--image= : URL opcional de imagen}';

    protected $description = 'Envia una notificacion de prueba a una unica suscripcion FCM Web';

    public function handle(WebPushSubscriptionService $subscriptions): int
    {
        $subscription = WebPushSubscription::query()->find((int) $this->argument('subscription'));
        if (! $subscription) {
            $this->error('La suscripcion indicada no existe.');

            return self::FAILURE;
        }

        $action = trim((string) $this->option('action')) ?: (string) config('services.fcm.web_home_url');
        if (! filter_var($action, FILTER_VALIDATE_URL)) {
            $this->error('Indica una URL absoluta valida con --action o configura FCM_WEB_HOME_URL.');

            return self::FAILURE;
        }

        try {
            $result = $subscriptions->sendTest(
                $subscription,
                (string) $this->option('title'),
                (string) $this->option('body'),
                $action,
                filled($this->option('image')) ? (string) $this->option('image') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result['sent']) {
            $this->error('Firebase rechazo el envio: '.$result['result']);
            if ($result['invalid']) {
                $this->warn('La suscripcion fue marcada como INVALIDA.');
            }

            return self::FAILURE;
        }

        $this->info("Push Web enviada a la suscripcion #{$subscription->getKey()}.");
        $this->line($result['result']);

        return self::SUCCESS;
    }
}
