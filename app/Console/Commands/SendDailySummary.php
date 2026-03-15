<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Memory;
use Carbon\Carbon;

class SendDailySummary extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'marlin:summary';

    /**
     * The console command description.
     */
    protected $description = 'Envía un resumen de las notas pendientes del día a cada usuario a las 6:00 AM';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        set_time_limit(120);
        $today = Carbon::now('America/Bogota')->format('Y-m-d');

        Log::info("Starting Daily Summary for date: $today");

        $users = User::whereNotNull('phone_number')->get();

        if ($users->isEmpty()) {
            $this->info("No users found to notify.");
            return;
        }

        foreach ($users as $user) {
            $this->processUserSummary($user, $today);
        }

        Log::info("Daily Summary completed.");
        $this->info("Summaries sent successfully!");
    }

    private function processUserSummary($user, $today)
    {
        // Get notes for today
        $notes = Memory::where('phone_number', $user->phone_number)
            ->whereDate('event_date', $today)
            ->get();

        try {
            $client = \Gemini::client(env('GEMINI_API_KEY'));

            if ($notes->isEmpty()) {
                $prompt = "Eres Marlin, un asistente amigable. El usuario {$user->name} no tiene tareas para hoy ($today). 
                           Salúdalo de forma creativa y deséale un gran día. Máximo 20 palabras. Usa emojis de mar.";
            } else {
                $tasksText = $notes->pluck('content')->implode(', ');
                $prompt = "Eres Marlin. El usuario {$user->name} tiene estas tareas hoy: $tasksText. 
                           Escribe un resumen motivador y corto que empiece por '¡Buenos días!'. 
                           Dile qué tiene pendiente de forma organizada. Usa emojis.";
            }

            $result = $client->generativeModel('gemini-2.5-flash')->generateContent($prompt);
            $message = trim($result->text());

            $this->sendWhatsAppMessage($user->phone_number, $message);
        } catch (\Exception $e) {
            Log::error("Error in Summary for user {$user->phone_number}: " . $e->getMessage());
        }
    }

    private function sendWhatsAppMessage($to, $text)
    {
        Http::withToken(env('WHATSAPP_TOKEN'))->post("https://graph.facebook.com/v20.0/" . env('WHATSAPP_PHONE_ID') . "/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text]
        ]);
    }
}
