<?php

use App\Models\User;
use App\Services\CouponAudienceEmailService;
use App\Services\Dashboard\AssetPublicationService;
use App\Services\PushNotificationService;
use App\Services\VipCustomerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sanctum:rotate-token {email : Correo del usuario tecnico} {--name=stj_api_token : Nombre base del token} {--keep-old : Conserva tokens anteriores}', function () {
    $email = (string) $this->argument('email');
    $tokenName = (string) $this->option('name');
    $keepOld = (bool) $this->option('keep-old');

    /** @var User|null $user */
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("No se encontro un usuario con email {$email}.");

        return self::FAILURE;
    }

    if (! $keepOld) {
        $user->tokens()
            ->when($tokenName !== '', fn ($query) => $query->where('name', $tokenName))
            ->delete();
    }

    $expirationMinutes = (int) config('sanctum.expiration', 0);
    $expiresAt = $expirationMinutes > 0
        ? Carbon::now()->addMinutes($expirationMinutes)
        : null;
    $token = $user->createToken($tokenName, ['*'], $expiresAt);

    $this->info('Token generado correctamente.');
    $this->line("Usuario: {$user->email}");
    $this->line("Nombre: {$tokenName}");
    $this->line('Expira: '.($expiresAt?->toDateTimeString() ?? 'sin expiracion'));
    $this->newLine();
    $this->warn('Guarda este token ahora. No volvera a mostrarse completo despues.');
    $this->line($token->plainTextToken);

    return self::SUCCESS;
})->purpose('Genera un nuevo token Sanctum para un usuario tecnico y opcionalmente revoca los anteriores');

Artisan::command('dashboard:issue-token {email=dashboard@stjacks.local : Correo del usuario tecnico del dashboard} {--name=stj_dashboard_token : Nombre del token} {--days=90 : Dias de vigencia del token}', function () {
    $email = (string) $this->argument('email');
    $tokenName = (string) $this->option('name');
    $expirationDays = max(1, (int) $this->option('days'));

    /** @var User $user */
    $user = User::firstOrCreate(
        ['email' => $email],
        [
            'name' => 'STJ Dashboard',
            'password' => Hash::make(str()->random(48)),
        ],
    );

    $user->tokens()
        ->where('name', $tokenName)
        ->delete();

    $expiresAt = Carbon::now()->addDays($expirationDays);
    $token = $user->createToken($tokenName, ['dashboard'], $expiresAt);

    $this->info('Token dashboard generado correctamente.');
    $this->line("Usuario: {$user->email}");
    $this->line("Nombre: {$tokenName}");
    $this->line('Ability: dashboard');
    $this->line('Expira: '.($expiresAt?->toDateTimeString() ?? 'sin expiracion'));
    $this->newLine();
    $this->warn('Guarda este token en STJ_API_DASHBOARD_TOKEN dentro de stj-dashboard/.env.');
    $this->line($token->plainTextToken);

    return self::SUCCESS;
})->purpose('Crea o reutiliza el usuario tecnico del dashboard y genera token Sanctum con ability dashboard');

Artisan::command('push:send-pending', function (PushNotificationService $pushNotifications) {
    $this->line("ST. JACK'S - Notificaciones PUSH");
    $this->line('INICIO => '.now()->toDateTimeString());

    $summary = $pushNotifications->sendPending();

    if ($summary['pending'] === 0) {
        $this->line('Notificaciones: No hay notificaciones a enviar');
    } else {
        $this->line("Pendientes: {$summary['pending']}");
        $this->line("Enviadas: {$summary['sent']}");
        $this->line("Errores: {$summary['failed']}");
    }

    $this->line('FIN => '.now()->toDateTimeString());

    return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
})->purpose('Envia las notificaciones push pendientes programadas');

Artisan::command('assets:put', function (AssetPublicationService $assets) {
    $this->line("ST. JACK'S WEB - PUT ASSETS");
    $this->line('INICIO => '.now()->toDateTimeString());

    $payload = $assets->publish();
    $summary = $payload['summary'] ?? [];

    $this->line('Assets finalizados: '.($summary['finished'] ?? 0));
    $this->line('Assets activados: '.($summary['activated'] ?? 0));
    $this->line('Paises procesados: '.($summary['countries'] ?? 0));
    $this->line('Archivo JSON: '.($summary['path'] ?? ''));
    $this->line('FIN => '.now()->toDateTimeString());

    return self::SUCCESS;
})->purpose('Activa/finaliza assets y publica storage/app/storefront/assets.json');

Artisan::command('coupons:send-pending-emails {--limit=25 : Máximo de correos por ejecución (límite duro: 25)}', function (CouponAudienceEmailService $emails) {
    $summary = $emails->sendPending((int) $this->option('limit'));
    $this->info("Pendientes: {$summary['pending']} | Enviados: {$summary['sent']} | Fallidos: {$summary['failed']} | Omitidos: {$summary['skipped']}");

    return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
})->purpose('Envía correos pendientes de cupones personales VIP y cargados por archivo');

Artisan::command('customers:update-vip', function (VipCustomerService $customers) {
    $summary = $customers->refresh();
    $this->info("VIP recalculados desde {$summary['since']}: {$summary['qualified']} | Reiniciados: {$summary['reset']} | Marcados: {$summary['marked']}");

    return self::SUCCESS;
})->purpose('Recalcula clientes VIP por compras aprobadas en los últimos seis meses');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('push:send-pending')->hourly();
Schedule::command('productos:calcular-metricas')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
Schedule::command('promotions:update')
    ->everyFiveMinutes()
    ->withoutOverlapping();
Schedule::command('coupons:send-pending-emails --limit=25')
    ->everyTenMinutes()
    ->withoutOverlapping(15);
Schedule::command('customers:update-vip')
    ->dailyAt('01:15')
    ->timezone('America/El_Salvador')
    ->withoutOverlapping(30);
Schedule::command('inventory:sync')
    ->everyFiveMinutes()
    ->between('08:00', '21:00')
    ->timezone('America/El_Salvador')
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/inventory-scheduler.log'));

if (config('push_web.automation_enabled')) {
    Schedule::command('push:web-evaluate --limit='.(int) config('push_web.evaluate_limit', 500))
        ->everyFifteenMinutes()
        ->withoutOverlapping(20);
    Schedule::command('push:web-send-pending --limit='.(int) config('push_web.send_limit', 100))
        ->everyFiveMinutes()
        ->withoutOverlapping(10);
}
