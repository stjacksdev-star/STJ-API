<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IssueCitasApiToken extends Command
{
    protected $signature = 'citas:issue-token
        {--name=STJ Citas : Nombre descriptivo del cliente}
        {--code=stj_citas : Código único en stj_api_clientes}
        {--domain=http://stj-citas.test/ : Dominio de referencia}
        {--days=365 : Días de vigencia}';

    protected $description = 'Crea o rota el token exclusivo de lectura de tiendas para STJ Citas';

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $code = trim((string) $this->option('code'));
        $domain = trim((string) $this->option('domain'));
        $days = max(1, (int) $this->option('days'));

        if ($name === '' || ! preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $code)) {
            $this->error('El nombre es obligatorio y el código solo admite letras, números, guion y guion bajo.');

            return self::FAILURE;
        }

        $rawToken = 'stj_citas_'.rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = now()->addDays($days);

        DB::transaction(function () use ($name, $code, $domain, $rawToken, $tokenHash, $expiresAt): void {
            DB::table('stj_api_clientes')->updateOrInsert(
                ['apc_codigo' => $code],
                [
                    'apc_nombre' => $name,
                    'apc_token_prefijo' => substr($rawToken, 0, 20),
                    'apc_token_hash' => $tokenHash,
                    'apc_permiso' => 'citas.stores.read',
                    // Campo obligatorio del esquema actual; el endpoint autoriza los cinco países definidos.
                    'apc_pais_id' => 1,
                    'apc_estado' => 'ACTIVO',
                    'apc_dominio_referencia' => $domain !== '' ? $domain : null,
                    'apc_expira_en' => $expiresAt,
                    'apc_ultimo_uso' => null,
                    'apc_revocado_en' => null,
                ],
            );
        });

        $this->info('Token de STJ Citas generado correctamente.');
        $this->line("Cliente: {$code}");
        $this->line('Permiso: citas.stores.read');
        $this->line('Dominio de referencia: '.($domain !== '' ? $domain : 'ninguno'));
        $this->line('Expira: '.$expiresAt->toDateTimeString());
        $this->newLine();
        $this->warn('Guarda este token ahora. Solo se almacena su hash y no volverá a mostrarse.');
        $this->line($rawToken);

        return self::SUCCESS;
    }
}
