<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\Memory;

class WhatsAppController extends Controller
{
    public function verifyWebhook(Request $request)
    {
        $verifyToken = 'Roger_Key_2026';
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                return response($challenge, 200);
            }
        }
        return response('Forbidden', 403);
    }

    public function handleWebhook(Request $request)
    {
        $data = $request->all();

        // Extraer info del mensaje
        $entry = $data['entry'][0] ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value = $changes['value'] ?? null;
        $message = $value['messages'][0] ?? null;

        if ($message && isset($message['text']['body'])) {
            $sender = $message['from'];
            $text = $message['text']['body'];

            //  Guardar en Base de Datos
            Memory::create([
                'phone_number' => $sender,
                'content' => $text,
                'event_date' => null
            ]);

            Log::info("Marlin guardó: " . $text);

            $this->sendMessage($sender, "✅ ¡Entendido! Ya guardé tu nota: \"{$text}\"");

            return response('EVENT_RECEIVED', 200);
        }

        return response('NO_MESSAGE', 200);
    }

    private function sendMessage($to, $text)
    {
        $token = env('WHATSAPP_TOKEN');
        $phoneId = env('WHATSAPP_PHONE_ID');
        $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";

        $response = Http::withToken($token)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text]
        ]);

        return $response->json();
    }
}