<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Tu comando de motivación cada hora
Schedule::command('marlin:motivar')
    ->hourly()
    ->between('08:00', '20:00')
    ->timezone('America/Bogota');

// NUEVO: Tu comando de resumen a las 6:00 AM
Schedule::command('marlin:summary')
    ->dailyAt('06:00')
    ->timezone('America/Bogota');