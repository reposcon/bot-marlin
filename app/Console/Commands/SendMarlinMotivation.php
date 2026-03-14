<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class SendMarlinMotivation extends Command
{
    protected $signature = 'marlin:motivar';
    protected $description = 'Envía una frase inspiradora de una figura mundial generada por IA';

    public function handle()
    {
        set_time_limit(60); // Seguridad extra para el comando

        try {
            Log::info("Iniciando motivación Marlin...");
            $client = \Gemini::client(env('GEMINI_API_KEY'));

            $prompt = "Genera una frase motivadora corta (máximo 15 palabras) de un famoso en español. Formato: 'Frase' - Autor.";

            // Usando el modelo de 2026 más veloz
            $res = $client->generativeModel('gemini-3.1-flash-lite-preview')->generateContent($prompt);
            $fraseIA = trim($res->text());

            $mensaje = "✨ *Marlin Inspirador* ✨\n\n" .
                "$fraseIA" . "\n\n" .
                "¡Sigue nadando! 🤡🧡🐟";

            $users = User::whereNotNull('phone_number')->get();

            foreach ($users as $user) {
                $this->sendMessage($user->phone_number, $mensaje);
            }

            Log::info("Motivación enviada a " . $users->count() . " usuarios.");
            $this->info("¡Todo listo!");

        } catch (\Exception $e) {
            Log::error("Fallo Motivación: " . $e->getMessage());
            $this->error("Error: " . $e->getMessage());
        }
    }

    private function sendMessage($to, $text)
    {
        Http::withToken(env('WHATSAPP_TOKEN'))->post("https://graph.facebook.com/v20.0/" . env('WHATSAPP_PHONE_ID') . "/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text]
        ]);
    }
}