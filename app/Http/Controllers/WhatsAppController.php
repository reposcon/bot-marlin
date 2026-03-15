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
            $messageTimestamp = $message['timestamp'];
            if ((time() - $messageTimestamp) > 120) return response('OK', 200);

            $sender = $message['from'];
            $text = trim($message['text']['body']);

            $user = User::where('phone_number', $sender)->first();

            // --- USER ONBOARDING ---
            if (!$user) {
                User::create(['phone_number' => $sender]);
                $this->sendMessage($sender, "¡Hola! Soy *Marlin*. 🤡🧡🐟\n\n¿Cómo te llamas? 🪸");
                return response('OK', 200);
            }

            if (empty($user->name)) {
                $user->update(['name' => $text]);
                $this->sendMessage($sender, "¡Mucho gusto, *{$text}*! 🤝 Ya estás en mi cardumen. ¿En qué te ayudo hoy? 🌊");
                return response('OK', 200);
            }

            // --- AI ORCHESTRATOR ---
            return $this->processWithAI($user, $text);
        }
        return response('OK', 200);
    }
    private function processWithAI($user, $text)
    {
        try {
            $now = Carbon::now('America/Bogota');
            $client = \Gemini::client(env('GEMINI_API_KEY'));

            $prompt = "Eres Marlin, asistente de notas. Usuario: {$user->name}. Hoy: {$now->format('Y-m-d')}.
        Mensaje: '$text'

        Responde ESTRICTAMENTE con este JSON. Si hay varias acciones, inclúyelas:
        {
          'intent': 'SAVE' | 'LIST' | 'DELETE' | 'UPDATE_PROFILE' | 'CHAT' | 'CLEAR_ALL',
          'actions': {
            'save': {'content': 'texto', 'date': 'YYYY-MM-DD o null'},
            'update_profile': {'new_name': 'nombre'},
            'delete': {'index': 'numero'},
            'list': {'start': 'YYYY-MM-DD', 'end': 'YYYY-MM-DD', 'label': 'hoy...'}
          },
          'reply': 'respuesta amigable en español confirmando todo lo hecho'
        }";

            $result = $client->generativeModel('gemini-2.5-flash')->generateContent($prompt);
            $rawText = $result->text();

            // --- LIMPIEZA EXTREMA DEL JSON ---
            $cleanJson = trim(preg_replace('/^```json|```$/m', '', $rawText));
            $startPos = strpos($cleanJson, '{');
            $endPos = strrpos($cleanJson, '}');
            if ($startPos !== false && $endPos !== false) {
                $cleanJson = substr($cleanJson, $startPos, $endPos - $startPos + 1);
            }

            $response = json_decode($cleanJson, true);

            // Si el JSON falla, lanzamos error para ver el log
            if (!$response) {
                Log::error("Marlin - JSON Corrupto: " . $rawText);
                throw new \Exception("IA devolvió basura");
            }

            $actions = $response['actions'] ?? [];

            // 1. Cambio de nombre (UPDATE_PROFILE)
            if (!empty($actions['update_profile']['new_name'])) {
                $user->update(['name' => $actions['update_profile']['new_name']]);
            }

            // 2. Guardado de nota (SAVE)
            if (!empty($actions['save']['content'])) {
                Memory::create([
                    'phone_number' => $user->phone_number,
                    'content' => $actions['save']['content'],
                    'event_date' => $actions['save']['date'] ?? null
                ]);
            }

            // 3. Borrado (DELETE) - IMPORTANTE: Castear a int
            if (!empty($actions['delete']['index'])) {
                $this->executeDeletion($user, (int)$actions['delete']['index']);
            }

            // 4. Listado (LIST)
            if ($response['intent'] === 'LIST' && isset($actions['list'])) {
                $this->executeListing($user, $actions['list']);
                return response('OK', 200);
            }

            // 5. Limpieza total
            if ($response['intent'] === 'CLEAR_ALL') {
                Memory::where('phone_number', $user->phone_number)->delete();
            }

            // Respuesta final
            $finalReply = $response['reply'] ?? "¡Listo! He procesado tu solicitud. 🤡";
            $this->sendMessage($user->phone_number, $finalReply);
        } catch (\Exception $e) {
            Log::error("Marlin AI Error Crítico: " . $e->getMessage());
            $this->sendMessage($user->phone_number, "Lo siento, me dio un calambre en la aleta. 🤡");
        }

        return response('OK', 200);
    }
    private function executeListing($user, $data)
    {
        $notes = Memory::where('phone_number', $user->phone_number)
            ->whereBetween('event_date', [$data['start'], $data['end']])
            ->orderBy('event_date', 'asc')->get();

        if ($notes->isEmpty()) {
            $this->sendMessage($user->phone_number, "No tienes nada para *{$data['label']}*, {$user->name}. 😎");
        } else {
            $message = "Esto hay para *{$data['label']}*: \n\n";
            foreach ($notes as $key => $note) {
                $date = $note->event_date ? Carbon::parse($note->event_date)->format('d/m') : 'S/F';
                $message .= "*" . ($key + 1) . ".* {$note->content} (📅 {$date})\n";
            }
            $message .= "\n_Dime 'borrar 1' si quieres quitar algo._";
            $this->sendMessage($user->phone_number, $message);
        }
    }

    private function executeDeletion($user, $index)
    {
        $notes = Memory::where('phone_number', $user->phone_number)->orderBy('event_date', 'asc')->get();
        $target = $notes->get($index - 1);

        if ($target) {
            $content = $target->content;
            $target->delete();
            $this->sendMessage($user->phone_number, "✅ Borrada la nota #{$index}: _{$content}_");
        } else {
            $this->sendMessage($user->phone_number, "No encontré la nota número {$index}.");
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
