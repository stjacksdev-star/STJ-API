<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagementVisitDetailsReportService
{
    public function report(string $startDate, string $endDate, string $country = 'GENERAL', string $platform = 'TODAS'): array
    {
        $start = Carbon::parse($startDate)->toDateString();
        $end = Carbon::parse($endDate)->toDateString();
        if ($start > $end) {
            throw ValidationException::withMessages(['endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.']);
        }

        $country = strtoupper(trim($country));
        $platform = strtoupper(trim($platform));
        $rows = collect([...$this->legacyRows($start, $end), ...$this->dailyRows($start, $end)])
            ->map(fn (array $row) => [
                'date' => $row['date'],
                'countryCode' => $this->countryCode($row['countryCode'], $row['country']),
                'country' => $this->countryName($row['countryCode'], $row['country']),
                'platform' => $this->platform($row['platform']),
                'visits' => (int) $row['visits'],
            ])
            ->when($country !== 'GENERAL', fn ($items) => $items->where('countryCode', $country))
            ->when($platform !== 'TODAS', fn ($items) => $items->where('platform', $platform))
            ->groupBy(fn (array $row) => implode('|', [$row['date'], $row['countryCode'], $row['platform']]))
            ->map(fn ($group) => [
                'date' => $group->first()['date'],
                'countryCode' => $group->first()['countryCode'],
                'country' => $group->first()['country'],
                'platform' => $group->first()['platform'],
                'visits' => $group->sum('visits'),
            ])
            ->sortBy([['date', 'asc'], ['country', 'asc'], ['platform', 'asc']])
            ->values();

        $dates = collect(CarbonPeriod::create($start, $end))->map->toDateString()->all();
        $platforms = $platform === 'TODAS' ? ['WEB', 'APP-IOS', 'APP-ANDROID'] : [$platform];

        return [
            'countries' => $this->countries(),
            'platforms' => ['WEB', 'APP-IOS', 'APP-ANDROID'],
            'filters' => compact('start', 'end', 'country', 'platform'),
            'summary' => ['visits' => $rows->sum('visits')],
            'rows' => $rows->all(),
            'chart' => [
                'categories' => $dates,
                'series' => collect($platforms)->map(fn (string $item) => [
                    'key' => $item,
                    'label' => $item,
                    'data' => collect($dates)->map(fn (string $date) => $rows->where('date', $date)->where('platform', $item)->sum('visits'))->all(),
                ])->all(),
            ],
        ];
    }

    private function legacyRows(string $start, string $end): array
    {
        if (! $range = $this->legacyRange($start, $end)) {
            return [];
        }
        $endBefore = Carbon::parse($range[1])->addDay()->startOfDay()->toDateTimeString();

        return DB::table('stj_visitas')->where('vis_fecha', '>=', $range[0].' 00:00:00')->where('vis_fecha', '<', $endBefore)
            ->groupByRaw('DATE(vis_fecha), vis_pais, vis_plataforma')
            ->selectRaw('DATE(vis_fecha) AS date, vis_pais AS country, vis_pais AS countryCode, vis_plataforma AS platform, COUNT(*) AS visits')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    private function dailyRows(string $start, string $end): array
    {
        if (! $range = $this->dailyRange($start, $end)) {
            return [];
        }

        return DB::table('stj_visitas_diarias as visits')->join('stj_paises as countries', 'countries.pai_id', '=', 'visits.vdi_pais_id')
            ->whereBetween('visits.vdi_fecha', $range)
            ->groupBy('visits.vdi_fecha', 'countries.pai_codigo', 'countries.pai_nombre', 'visits.vdi_origen')
            ->selectRaw('visits.vdi_fecha AS date, countries.pai_nombre AS country, countries.pai_codigo AS countryCode, visits.vdi_origen AS platform, COUNT(*) AS visits')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    private function countries(): array
    {
        return DB::table('stj_paises')->orderBy('pai_nombre')->get(['pai_codigo', 'pai_nombre'])
            ->map(fn ($row) => ['code' => strtoupper((string) $row->pai_codigo), 'name' => (string) $row->pai_nombre])->all();
    }

    private function platform(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        return match ($value) {
            'APP-IOS', 'IOS' => 'APP-IOS',
            'APP-ANDROID', 'ANDROID' => 'APP-ANDROID',
            default => 'WEB',
        };
    }

    private function countryCode(?string $code, ?string $name): string
    {
        $value = strtolower(str_replace([' ', '-', '_'], '', trim((string) ($code ?: $name))));

        return match ($value) {
            'elsalvador', 'sv', 'es' => 'SV', 'guatemala', 'gt' => 'GT', 'costarica', 'cr' => 'CR',
            'honduras', 'hn' => 'HN', 'venezuela', 've' => 'VE', 'panama', 'panamá', 'pa' => 'PA',
            default => strtoupper(trim((string) ($code ?: 'ND'))),
        };
    }

    private function countryName(?string $code, ?string $name): string
    {
        return match ($this->countryCode($code, $name)) {
            'SV' => 'El Salvador', 'GT' => 'Guatemala', 'CR' => 'Costa Rica', 'HN' => 'Honduras',
            'VE' => 'Venezuela', 'PA' => 'Panamá', default => trim((string) $name) ?: 'N/D',
        };
    }

    private function legacyRange(string $start, string $end): ?array
    {
        $cutoff = $this->cutoff();
        $legacyEnd = $cutoff ? min($end, Carbon::parse($cutoff)->subDay()->toDateString()) : $end;

        return $start <= $legacyEnd ? [$start, $legacyEnd] : null;
    }

    private function dailyRange(string $start, string $end): ?array
    {
        $cutoff = $this->cutoff();
        if (! $cutoff) {
            return null;
        }
        $dailyStart = max($start, $cutoff);

        return $dailyStart <= $end ? [$dailyStart, $end] : null;
    }

    private function cutoff(): ?string
    {
        $cutoff = trim((string) config('analytics.daily_visits_cutoff_date', ''));

        return $cutoff === '' ? null : Carbon::parse($cutoff)->toDateString();
    }
}
