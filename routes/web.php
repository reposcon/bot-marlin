<?php

use Illuminate\Support\Facades\Route;
use Gemini\Laravel\Facades\Gemini;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/check-models', function () {
    try {
        // Esto le pide a Google la lista de modelos que TU API Key puede usar
        $response = Gemini::models()->list();
        return $response->models;
    } catch (\Exception $e) {
        return $e->getMessage();
    }
});