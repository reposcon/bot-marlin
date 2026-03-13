<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class SendMarlinMotivation extends Command
{
    /**
     * El nombre que usarás en la terminal: php artisan marlin:motivar
     */
    protected $signature = 'marlin:motivar';

    /**
     * Descripción de lo que hace el comando.
     */
    protected $description = 'Envía una frase inspiradora de una figura mundial generada por IA';

    public function handle()
    {
        try {
            Log::info("Iniciando generación de motivación con IA...");
            
            $client = \Gemini::client(env('GEMINI_API_KEY'));

            $prompt = "Genera una frase motivadora corta (máximo 15 palabras) de un cantante famoso, 
                       líder mundial, deportista o figura histórica icónica en español. 
                       Formato: 'Frase' - Autor. 
                       IMPORTANTE: Que sea inspirador. Evita frases trilladas.";

            $res = $client->generativeModel('gemini-2.5-flash')->generateContent($prompt);
            $fraseIA = trim($res->text());

            $mensaje = "✨ *Marlin Inspirador* ✨\n\n" .
                "$fraseIA" . "\n\n" .
                "¡Sigue nadando, el éxito está cerca! 🤡🧡🐟";

            $users = User::whereNotNull('phone_number')->get();

            foreach ($users as $user) {
                $this->sendMessage($user->phone_number, $mensaje);
            }

            Log::info("Motivación enviada con éxito a " . $users->count() . " usuarios.");
            $this->info("Mensajes enviados correctamente.");

        } catch (\Exception $e) {
            Log::error("Fallo al generar frase motivacional: " . $e->getMessage());
            $this->error("Error: " . $e->getMessage());
        }
    }

    /**
     * Método para enviar el mensaje vía WhatsApp (necesario dentro del comando)
     */
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