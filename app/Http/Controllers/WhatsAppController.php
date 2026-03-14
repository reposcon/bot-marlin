<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\Memory;
use App\Models\User;
use Carbon\Carbon;

class WhatsAppController extends Controller
{
    public function verifyWebhook(Request $request)
    {
        $verifyToken = 'Roger_Key_2026';
        if ($request->query('hub_mode') === 'subscribe' && $request->query('hub_verify_token') === $verifyToken) {
            return response($request->query('hub_challenge'), 200);
        }
        return response('Forbidden', 403);
    }

    public function handleWebhook(Request $request)
    {
        set_time_limit(60);
        $data = $request->all();
        $message = $data['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

        if ($message && isset($message['text']['body'])) {
            $messageTimestamp = $message['timestamp'];
            if ((time() - $messageTimestamp) > 120) return response('OK', 200);

            $sender = $message['from'];
            $text = trim($message['text']['body']);
            $user = User::where('phone_number', $sender)->first();

            if (!$user) {
                User::create(['phone_number' => $sender]);
                $this->sendMessage($sender, "¡Hola! Soy *Marlin*. 🤡🧡🐟\n\n¿Cómo te llamas? 🪸");
                return response('OK', 200);
            }

            if (empty($user->name)) {
                $user->update(['name' => $text]);
                $this->sendMessage($sender, "¡Mucho gusto, *{$text}*! 🤝");
                return response('OK', 200);
            }

            return $this->procesarPeticion($user, $text);
        }
        return response('OK', 200);
    }

    private function procesarPeticion($user, $text)
    {
        $cleanText = trim(preg_replace('/^marlin[\s,.]+/i', '', $text));
        if (empty($cleanText)) {
            $cleanText = $text;
        }

        // Borrar todo (Lógica simple)
        if (preg_match('/\b(borrar todo|limpiar agenda|vaciar notas)\b/i', $text)) {
            Memory::where('phone_number', $user->phone_number)->delete();
            $this->sendMessage($user->phone_number, "¡Listo {$user->name}! He vaciado tu lista. ✨");
            return response('OK', 200);
        }

        // Listar notas
        if (preg_match('/\b(lista|ver|notas|pendientes|tengo|hay|agenda)\b/i', $text)) {
            return $this->handleListRequest($user, $text);
        }

        // Si no es ninguna de las anteriores, es GUARDAR
        return $this->handleSaveRequest($user, $cleanText);
    }

    private function handleListRequest($user, $text)
    {
        $hoy = Carbon::now();
        $data = ['start' => $hoy->format('Y-m-d'), 'end' => $hoy->format('Y-m-d'), 'label' => 'hoy'];

        try {
            $client = \Gemini::client(env('GEMINI_API_KEY'));
            $prompt = "Hoy es {$hoy->format('Y-m-d')}. El usuario dice: '$text'. Responde SOLO un JSON con: 'start', 'end', 'label'.";

            $result = $client->generativeModel('gemini-1.5-flash')->generateContent($prompt);
            $cleanJson = trim(preg_replace('/^```json|```$/m', '', $result->text()));
            $decoded = json_decode($cleanJson, true);

            if ($decoded && isset($decoded['start'])) {
                $data = $decoded;
            }
        } catch (\Exception $e) {
            Log::error("Error IA List: " . $e->getMessage());
        }

        $notes = Memory::where('phone_number', $user->phone_number)
            ->whereBetween('event_date', [$data['start'], $data['end']])
            ->orderBy('event_date', 'asc')->get();

        if ($notes->isEmpty()) {
            $this->sendMessage($user->phone_number, "Oye {$user->name}, para *{$data['label']}* no hay nada. 😎");
        } else {
            $msg = "Vale {$user->name}, esto hay para *{$data['label']}*: \n\n";
            foreach ($notes as $key => $n) {
                $msg .= "*" . ($key + 1) . ".* {$n->content} (📅 " . Carbon::parse($n->event_date)->format('d/m') . ")\n";
            }
            $this->sendMessage($user->phone_number, $msg);
        }
        return response('OK', 200);
    }

    private function handleSaveRequest($user, $text)
    {
        $eventDate = null;
        $keywords = '/(hoy|mañana|pasado|lunes|martes|miercoles|jueves|viernes|sabado|domingo|enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre|el \d+)/i';

        if (preg_match($keywords, $text)) {
            try {
                $client = \Gemini::client(env('GEMINI_API_KEY'));
                $prompt = "Extrae fecha YYYY-MM-DD de: '$text'. Hoy es " . date('Y-m-d') . ". Si no hay, responde 'null'.";

                $res = $client->generativeModel('gemini-1.5-flash')->generateContent($prompt);
                $extracted = trim($res->text());

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $extracted)) {
                    $eventDate = $extracted;
                }
            } catch (\Exception $e) {
                Log::error("Error IA Save: " . $e->getMessage());
            }
        }

        Memory::create([
            'phone_number' => $user->phone_number,
            'content' => $text,
            'event_date' => $eventDate
        ]);

        $msg = "¡Anotado, {$user->name}! ✅" . ($eventDate ? " para el " . Carbon::parse($eventDate)->format('d/m') . "." : "");
        $this->sendMessage($user->phone_number, $msg);

        return response('OK', 200);
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
