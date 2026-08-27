<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Daily visits source cutover
    |--------------------------------------------------------------------------
    |
    | Dates before this value are read from the legacy stj_visitas table.
    | This date and later are read from stj_visitas_diarias. Leave it empty to
    | keep the dashboard entirely on the legacy source until production launch.
    |
    */
    'daily_visits_cutoff_date' => env('DAILY_VISITS_CUTOFF_DATE'),
];
