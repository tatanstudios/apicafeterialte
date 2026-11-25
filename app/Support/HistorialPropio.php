<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Historiales por fecha (ventas, compras, gastos, producción): quien no tiene
 * «historial.ver_todo» (los meseros) ve solo lo que registró él mismo.
 * El admin pasa siempre (Gate::before).
 */
class HistorialPropio
{
    public static function aplicar(Builder $consulta, string $columna = 'usuario_id'): Builder
    {
        $usuario = auth()->user();

        if ($usuario && !$usuario->can('historial.ver_todo')) {
            $consulta->where($consulta->getModel()->qualifyColumn($columna), $usuario->id);
        }

        return $consulta;
    }
}
