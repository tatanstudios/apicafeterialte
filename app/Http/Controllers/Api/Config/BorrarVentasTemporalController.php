<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * TEMPORAL (pruebas): botón «Borrar ventas» en Unidades de medida.
 * Hace lo mismo que `php artisan pos:limpiar-movimientos` (con respaldo antes) pero
 * sin cerrar las sesiones. Solo para el admin, y solo con APP_ENV=local o con
 * PERMITIR_BORRAR_VENTAS=true en el .env (servidor en etapa de pruebas); si no, 403.
 * En el servidor solo se guarda el último respaldo «antes_de_limpiar» (los anteriores se borran).
 * Para quitarlo: este archivo, su ruta en routes/api.php y el botón en UnidadMedida.
 */
class BorrarVentasTemporalController extends Controller
{
    public function __invoke(Request $request)
    {
        $local = app()->environment('local');
        if (!($local || config('app.permitir_borrar_ventas')) || !$request->user()?->hasRole('admin')) {
            return response()->json(['success' => 0, 'message' => 'Borrar ventas está desactivado en este servidor (solo el administrador, con PERMITIR_BORRAR_VENTAS=true)'], 403);
        }

        $codigo = Artisan::call('pos:limpiar-movimientos', ['--force' => true, '--conservar-sesiones' => true]);
        $salida = trim(Artisan::output());

        if ($codigo !== 0) {
            return response()->json(['success' => 0, 'message' => 'No se borró nada: ' . $salida], 500);
        }

        // En el servidor no se acumulan respaldos: queda solo el más reciente de antes de limpiar
        if (!$local) {
            $viejos = collect(File::glob(storage_path('app/respaldos/antes_de_limpiar_*.sql.gz')))->sort()->values();
            $viejos->slice(0, -1)->each(fn ($f) => File::delete($f));
        }

        return response()->json([
            'success' => 1,
            'message' => 'Se borraron ventas, turnos, compras, gastos, producción, kárdex y auditoría. Las existencias quedaron en 0.',
        ]);
    }
}
