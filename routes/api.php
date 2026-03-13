<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;

Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verifyWebhook']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'handleWebhook']);