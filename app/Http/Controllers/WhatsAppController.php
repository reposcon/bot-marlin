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
        $data = $request->all();
        $message = $data['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

        if ($message && isset($message['text']['body'])) {
            // --- FILTRO DE MENSAJES ANTIGUOS ---
            $messageTimestamp = $message['timestamp']; // Timestamp que envía Meta
            $currentTime = time();

            // Si el mensaje tiene más de 120 segundos (2 min), lo ignoramos
            if (($currentTime - $messageTimestamp) > 120) {
                Log::warning("Ignorando mensaje antiguo acumulado: " . $message['text']['body']);
                return response('OK', 200); // Decimos OK a Meta para que deje de reintentar
            }
            // ------------------------------------

            $sender = $message['from'];
            $text = trim($message['text']['body']);

            Log::info("--- Nuevo Mensaje Recibido ---", ['de' => $sender, 'texto' => $text]);

            $user = User::where('phone_number', $sender)->first();

            if (!$user) {
                User::create(['phone_number' => $sender]);
                $this->sendMessage($sender, "¡Hola! Soy *Marlin*. 🤡🧡🐟\n\n" .
                    "Como diría mi hijo Nemo: \"¡El mar no es tan malo, papá!\". " .
                    "Pero por si acaso, yo estoy aquí para que no se te pierda ninguna nota en este océano de pendientes. 🌊🐚\n\n" .
                    "Antes de empezar nuestra travesía... ¿cómo te llamas? 🪸");
                return response('OK', 200);
            }

            if (empty($user->name)) {
                $user->update(['name' => $text]);
                $this->sendMessage($sender, "¡Mucho gusto, *{$text}*! 🤝 Ya te tengo en mi cardumen. 🐟✨\n\n" .
                    "A partir de ahora, solo mándame lo que necesites recordar o pídeme tu lista. " .
                    "¡Nadaremos, nadaremos, en notas guardaremos! 🌊💨");
                return response('OK', 200);
            }

            return $this->procesarPeticion($user, $text);
        }
        return response('OK', 200);
    }
    private function procesarPeticion($user, $text)
    {
        // Limpieza de "marlin" con coma, punto o espacio
        $cleanText = trim(preg_replace('/^marlin[\s,.]+/i', '', $text));

        // Si el usuario solo puso "marlin", usamos el texto original para no guardar vacío
        if (empty($cleanText)) {
            $cleanText = $text;
        }

        if (preg_match('/\b(borrar todo|limpiar agenda|vaciar notas)\b/i', $text)) {
            Memory::where('phone_number', $user->phone_number)->delete();
            $this->sendMessage($user->phone_number, "¡Listo {$user->name}! He vaciado toda tu lista. ✨");
            return response('OK', 200);
        }

        if (preg_match('/\b(borra|elimina|quitar|delete)\b/i', $text)) {
            return $this->handleDeleteRequest($user, $text);
        }

        if (preg_match('/\b(lista|ver|notas|pendientes|tengo|hay|agenda)\b/i', $text)) {
            return $this->handleListRequest($user, $text);
        }

        return $this->handleSaveRequest($user, $cleanText);
    }

    private function handleListRequest($user, $text)
    {
        $hoy = Carbon::now();
        $data = ['start' => $hoy->format('Y-m-d'), 'end' => $hoy->format('Y-m-d'), 'label' => 'hoy'];

        try {
            $client = \Gemini::client(env('GEMINI_API_KEY'));
            $prompt = "Hoy es {$hoy->format('Y-m-d')}. El usuario ({$user->name}) dice: '$text'. Devuelve JSON con: 'start', 'end', 'label'.";
            $result = $client->generativeModel('gemini-2.5-flash')->generateContent($prompt);
            $cleanJson = trim(preg_replace('/^```json|```$/m', '', $result->text()));
            $decoded = json_decode($cleanJson, true);

            if ($decoded && isset($decoded['start'])) {
                $data = $decoded;
            }
        } catch (\Exception $e) {
            $this->handleAiError($user, $e);
        }

        $notes = Memory::where('phone_number', $user->phone_number)
            ->whereBetween('event_date', [$data['start'], $data['end']])
            ->orderBy('event_date', 'asc')->get();

        if ($notes->isEmpty()) {
            $this->sendMessage($user->phone_number, "Oye {$user->name}, para *{$data['label']}* no encontré nada. 😎");
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
                Log::info("Consultando IA para extraer fecha...");
                $client = \Gemini::client(env('GEMINI_API_KEY'));
                $prompt = "Extrae fecha YYYY-MM-DD de: '$text'. Hoy es " . date('Y-m-d') . ". Si no hay, responde 'null'.";
                $res = $client->generativeModel('gemini-2.5-flash')->generateContent($prompt);
                $extracted = trim($res->text());

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $extracted)) {
                    $eventDate = $extracted;
                    Log::info("Fecha extraída con éxito: " . $eventDate);
                }
            } catch (\Exception $e) {
                Log::error("Fallo IA en Save (Silenciado): " . $e->getMessage());
            }
        }

        // GUARDADO GARANTIZADO
        Memory::create([
            'phone_number' => $user->phone_number,
            'content' => $text,
            'event_date' => $eventDate
        ]);
        Log::info("Nota guardada en BD para: " . $user->phone_number);

        $msg = "¡Anotado, {$user->name}! ✅";
        if ($eventDate) {
            $msg .= " para el " . Carbon::parse($eventDate)->format('d/m') . ".";
        }

        // Enviamos y logueamos la acción
        $this->sendMessage($user->phone_number, $msg);
        Log::info("Respuesta enviada a WhatsApp.");

        return response('OK', 200);
    }

    private function handleDeleteRequest($user, $text)
    {
        preg_match('/\d+/', $text, $matches);
        $index = isset($matches[0]) ? (int)$matches[0] : null;

        if ($index) {
            $noteToDelete = Memory::where('phone_number', $user->phone_number)
                ->orderBy('created_at', 'desc')->skip($index - 1)->first();

            if ($noteToDelete) {
                $content = $noteToDelete->content;
                $noteToDelete->delete();
                $this->sendMessage($user->phone_number, "¡Hecho! Borré *\"$content\"* ✅");
            } else {
                $this->sendMessage($user->phone_number, "Ups, no encontré la nota número $index.");
            }
        } else {
            $this->sendMessage($user->phone_number, "{$user->name}, dime el número de la nota a borrar.");
        }
        return response('OK', 200);
    }

    private function handleAiError($user, $e)
    {
        if (str_contains($e->getMessage(), 'quota')) {
            Log::warning("Quota reached for {$user->name}");
        } else {
            Log::error("Error de IA: " . $e->getMessage());
        }
    }

    private function sendMessage($to, $text)
    {
        $response = Http::withToken(env('WHATSAPP_TOKEN'))->post("https://graph.facebook.com/v20.0/" . env('WHATSAPP_PHONE_ID') . "/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text]
        ]);

        if ($response->failed()) {
            Log::error("Error enviando a Meta API: " . $response->body());
        } else {
            Log::info("Mensaje entregado a Meta correctamente.");
        }
    }
}
