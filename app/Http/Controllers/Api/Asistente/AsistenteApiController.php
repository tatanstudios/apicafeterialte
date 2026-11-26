<?php

namespace App\Http\Controllers\Api\Asistente;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Services\AsistenteService;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;

class AsistenteApiController extends Controller
{
    // POST /asistente/chat: la conversación vive en el cliente; se manda completa en cada pregunta
    public function chat(Request $request)
    {
        $datos = $request->validate([
            'mensajes'           => 'required|array|min:1|max:20',
            'mensajes.*.role'    => 'required|in:user,assistant',
            'mensajes.*.content' => 'required|string|max:4000',
        ]);

        // Una pregunta puede encadenar varias llamadas a OpenAI
        set_time_limit(180);

        $mensajes = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $datos['mensajes']);
        $resultado = AsistenteService::responder($mensajes);

        $pregunta = collect($mensajes)->where('role', 'user')->last()['content'] ?? '';
        AuditoriaService::registrar('asistente', 'consulta', null, null,
            ['pregunta' => mb_substr($pregunta, 0, 500), 'consultas' => $resultado['consultas'], 'tokens' => $resultado['tokens']],
            'Pregunta al asistente: ' . $pregunta);

        // Lo que el asistente no pudo responder queda aparte para agregar esa consulta después
        foreach ($resultado['sin_respuesta'] as $falta) {
            AuditoriaService::registrar('asistente', 'sin_respuesta', null, null,
                ['pregunta' => mb_substr($pregunta, 0, 500), 'resumen' => $falta['pregunta'], 'falta' => $falta['falta']],
                'Sin respuesta: ' . $pregunta);
        }

        return response()->json(['success' => 1, ...$resultado]);
    }

    /** Ids de las preguntas sin respuesta ya marcadas como resueltas (cada marca es otra entrada de Auditoría). */
    private function resueltas(): array
    {
        return Auditoria::query()->where('modulo', 'asistente')->where('accion', 'sin_respuesta_resuelta')
            ->pluck('datos_despues')->map(fn ($d) => (int) ($d['auditoria_id'] ?? 0))->filter()->all();
    }

    // GET /asistente/sin-respuesta: preguntas que el asistente no pudo contestar y siguen pendientes
    public function sinRespuesta()
    {
        $filas = Auditoria::query()->with('usuario:id,nombre')
            ->where('modulo', 'asistente')->where('accion', 'sin_respuesta')
            ->whereNotIn('id', $this->resueltas())
            ->latest('id')->limit(200)->get()
            ->map(fn ($a) => [
                'id'       => $a->id,
                'fecha'    => $a->created_at?->toDateTimeString(),
                'usuario'  => $a->usuario?->nombre,
                'pregunta' => $a->datos_despues['pregunta'] ?? '',
                'falta'    => $a->datos_despues['falta'] ?? '',
            ]);

        return response()->json(['success' => 1, 'preguntas' => $filas]);
    }

    // POST /asistente/sin-respuesta/{id}/resolver: la quita de pendientes; el registro original no se toca
    public function resolver(int $id)
    {
        $pregunta = Auditoria::query()->where('modulo', 'asistente')->where('accion', 'sin_respuesta')->findOrFail($id);

        if (!in_array($pregunta->id, $this->resueltas(), true)) {
            AuditoriaService::registrar('asistente', 'sin_respuesta_resuelta', null, null,
                ['auditoria_id' => $pregunta->id, 'pregunta' => $pregunta->datos_despues['pregunta'] ?? ''],
                'Pregunta sin respuesta marcada como resuelta: ' . ($pregunta->datos_despues['pregunta'] ?? ''));
        }

        return response()->json(['success' => 1, 'message' => 'Marcada como resuelta']);
    }
}
