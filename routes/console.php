<?php

use Illuminate\Foundation\Inspiring;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Carbon;
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

Artisan::command('dashboard:issue-token {email=dashboard@stjacks.local : Correo del usuario tecnico del dashboard} {--name=stj_dashboard_token : Nombre del token}', function () {
    $email = (string) $this->argument('email');
    $tokenName = (string) $this->option('name');

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

    $expirationMinutes = (int) config('sanctum.expiration', 0);
    $expiresAt = $expirationMinutes > 0
        ? Carbon::now()->addMinutes($expirationMinutes)
        : null;
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

Schedule::command('sanctum:prune-expired --hours=24')->daily();
