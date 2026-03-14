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

            // Registro de usuario nuevo
            if (!$user) {
                User::create(['phone_number' => $sender]);
                $this->sendMessage($sender, "¡Hola! Soy *Marlin*. 🤡🧡🐟\n\n¿Cómo te llamas? 🪸");
                return response('OK', 200);
            }

            // Captura de nombre
            if (empty($user->name)) {
                $user->update(['name' => $text]);
                $this->sendMessage($sender, "¡Mucho gusto, *{$text}*! 🤝 ¿En qué puedo ayudarte hoy?");
                return response('OK', 200);
            }

            return $this->procesarPeticion($user, $text);
        }
        return response('OK', 200);
    }

    private function procesarPeticion($user, $text)
    {
        // 1. Borrar TODO
        if (preg_match('/\b(borrar todo|limpiar agenda|vaciar notas)\b/i', $text)) {
            Memory::where('phone_number', $user->phone_number)->delete();
            $this->sendMessage($user->phone_number, "¡Listo {$user->name}! He vaciado tu lista por completo. ✨");
            return response('OK', 200);
        }

        // 2. Borrar una nota específica por número (Ej: "borrar 2")
        if (preg_match('/\b(borrar|eliminar|quitar)\s+(\d+)\b/i', $text, $matches)) {
            return $this->handleDeleteSpecific($user, $matches[2]);
        }

        // 3. Listar notas con filtros (IA)
        if (preg_match('/\b(lista|ver|notas|pendientes|tengo|hay|agenda)\b/i', $text)) {
            return $this->handleListRequest($user, $text);
        }

        // 4. Si no es comando, es GUARDAR nota
        return $this->handleSaveRequest($user, $text);
    }

    private function handleDeleteSpecific($user, $index)
    {
        // Obtenemos las notas actuales para identificar cuál es la que el usuario quiere borrar
        $notes = Memory::where('phone_number', $user->phone_number)
            ->orderBy('event_date', 'asc')
            ->get();

        $target = $notes->get($index - 1); // get() usa índice base 0

        if ($target) {
            $contenidoEliminado = $target->content;
            $target->delete();
            $this->sendMessage($user->phone_number, "✅ He eliminado la nota #{$index}: _{$contenidoEliminado}_");
        } else {
            $this->sendMessage($user->phone_number, "❌ No encontré ninguna nota con el número *{$index}* en tu lista actual.");
        }
        return response('OK', 200);
    }

    private function handleListRequest($user, $text)
    {
        $hoy = Carbon::now('America/Bogota');
        $data = ['start' => $hoy->format('Y-m-d'), 'end' => $hoy->format('Y-m-d'), 'label' => 'hoy'];

        try {
            $client = \Gemini::client(env('GEMINI_API_KEY'));
            $prompt = "Hoy es {$hoy->format('Y-m-d')} ({$hoy->format('l')}). 
                       El usuario dice: '$text'. 
                       Analiza si pide hoy, mañana, o esta semana. 
                       Responde estrictamente un JSON: {'start': 'YYYY-MM-DD', 'end': 'YYYY-MM-DD', 'label': 'nombre del periodo'}.";

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
            $this->sendMessage($user->phone_number, "{$user->name}, no tienes nada anotado para *{$data['label']}*. 😎");
        } else {
            $msg = "Vale {$user->name}, esto tienes para *{$data['label']}*: \n\n";
            foreach ($notes as $key => $n) {
                $fechaFormateada = $n->event_date ? Carbon::parse($n->event_date)->format('d/m') : 'S/F';
                $msg .= "*" . ($key + 1) . ".* {$n->content} (📅 {$fechaFormateada})\n";
            }
            $msg .= "\n_Si quieres quitar una, dime 'borrar [número]'_";
            $this->sendMessage($user->phone_number, $msg);
        }
        return response('OK', 200);
    }

    private function handleSaveRequest($user, $text)
    {
        $eventDate = null;
        $cleanText = trim(preg_replace('/^marlin[\s,.]+/i', '', $text));

        // Detectar si el mensaje menciona una fecha
        $keywords = '/(hoy|mañana|pasado|lunes|martes|miercoles|jueves|viernes|sabado|domingo|enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre|el \d+)/i';

        if (preg_match($keywords, $text)) {
            try {
                $client = \Gemini::client(env('GEMINI_API_KEY'));
                $prompt = "Extrae la fecha en formato YYYY-MM-DD del texto: '$text'. Hoy es " . date('Y-m-d') . ". Si no hay fecha clara, responde 'null'.";

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
            'content' => $cleanText,
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