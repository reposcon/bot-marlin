<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('marlin:motivar')
    ->hourly()
    ->between('08:00', '20:00')
    ->timezone('America/Bogota');

// Schedule::command('marlin:motivar')
//     ->everyFiveMinutes() 
//     ->between('08:00', '20:00')
//     ->timezone('America/Bogota');