<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class SendMarlinMotivation extends Command
{
    protected $signature = 'marlin:motivar';
    protected $description = 'Envía una frase inspiradora variada usando un seed aleatorio para evitar repeticiones';

    public function handle()
    {
        set_time_limit(60);

        try {
            Log::info("Iniciando motivación Marlin...");
            
            // Inicializar cliente
            $client = \Gemini::client(env('GEMINI_API_KEY'));
            $model = $client->generativeModel('gemini-3.1-flash-lite-preview');

            // --- TRUCO DE VARIABILIDAD ---
            // Usamos un número aleatorio y un tema distinto para que el prompt NUNCA sea igual
            $temas = ['resiliencia', 'disciplina', 'paciencia', 'éxito', 'superación', 'creatividad'];
            $temaAleatorio = $temas[array_rand($temas)];
            $seed = rand(1, 9999);

            $prompt = "Genera una frase motivadora corta (máximo 15 palabras) sobre $temaAleatorio. 
                       Usa un autor famoso pero no repitas las frases. 
                       Referencia única de sesión: $seed. 
                       Formato: 'Frase' - Autor.";

            // Generar contenido
            $res = $model->generateContent($prompt);
            $fraseIA = trim($res->text());

            // Estructura del mensaje para WhatsApp
            $mensaje = "✨ *Marlin Inspirador* ✨\n\n" .
                "$fraseIA" . "\n\n" .
                "¡Sigue nadando! 🤡🧡🐟";

            // Obtener usuarios
            $users = User::whereNotNull('phone_number')->get();

            if ($users->isEmpty()) {
                Log::warning("Marlin: No hay usuarios con teléfono para motivar.");
                $this->warn("No hay usuarios en la DB.");
                return;
            }

            // Enviar mensajes
            foreach ($users as $user) {
                $this->sendMessage($user->phone_number, $mensaje);
            }

            Log::info("Marlin: Motivación enviada a " . $users->count() . " usuarios con el tema: $temaAleatorio.");
            $this->info("¡Comando ejecutado con éxito!");

        } catch (\Exception $e) {
            Log::error("Fallo Crítico en Marlin: " . $e->getMessage());
            $this->error("Error: " . $e->getMessage());
        }
    }

    /**
     * Envía el mensaje a la API de WhatsApp de Meta
     */
    private function sendMessage($to, $text)
    {
        // Limpiamos el número: solo dejamos dígitos
        $cleanPhone = preg_replace('/[^0-9]/', '', $to);

        try {
            Http::withToken(env('WHATSAPP_TOKEN'))
                ->post("https://graph.facebook.com/v20.0/" . env('WHATSAPP_PHONE_ID') . "/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $cleanPhone,
                    'type' => 'text',
                    'text' => ['body' => $text]
                ]);
        } catch (\Exception $e) {
            Log::error("Error enviando a $cleanPhone: " . $e->getMessage());
        }
    }
}