<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * TEMPORAL (pruebas): botón «Borrar ventas» en Unidades de medida.
 * Hace lo mismo que `php artisan pos:limpiar-movimientos` (con respaldo antes) pero
 * sin cerrar las sesiones. Solo funciona con APP_ENV=local y para el admin:
 * en producción responde 403 aunque el botón siga en la web.
 * Para quitarlo: este archivo, su ruta en routes/api.php y el botón en UnidadMedida.
 */
class BorrarVentasTemporalController extends Controller
{
    public function __invoke(Request $request)
    {
        if (!app()->environment('local') || !$request->user()?->hasRole('admin')) {
            return response()->json(['success' => 0, 'message' => 'Solo disponible en el entorno local para el administrador'], 403);
        }

        $codigo = Artisan::call('pos:limpiar-movimientos', ['--force' => true, '--conservar-sesiones' => true]);
        $salida = trim(Artisan::output());

        if ($codigo !== 0) {
            return response()->json(['success' => 0, 'message' => 'No se borró nada: ' . $salida], 500);
        }

        return response()->json([
            'success' => 1,
            'message' => 'Se borraron ventas, turnos, compras, gastos, producción, kárdex y auditoría. Las existencias quedaron en 0.',
        ]);
    }
}
