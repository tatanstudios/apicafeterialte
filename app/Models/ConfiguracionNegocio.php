<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionNegocio extends Model
{
    protected $table = 'configuracion_negocio';

    protected $fillable = [
        'nombre',
        'razon_social',
        'nit',
        'nrc',
        'actividad_economica',
        'tipo_establecimiento',
        'direccion',
        'telefono',
        'correo',
        'logo',
        'moneda',
        'simbolo_moneda',
        'permitir_stock_negativo',
        'descuento_max_sin_autorizacion',
        'cierre_ciego',
        'aviso_cambio',
        'ticket_por_comprar',
        'ticket_cierre',
        'mensaje_ticket',
    ];

    /**
     * Secciones del ticket de cierre que el administrador puede apagar (todas encendidas por defecto).
     * «POR COMPRAR» sigue en su propia columna, ticket_por_comprar.
     */
    const SECCIONES_CIERRE = [
        'total_vendido'      => 'Total vendido y número de ventas',
        'movimientos'        => 'Fondo, ventas, ingresos y egresos',
        'por_metodo'         => 'Por forma de pago',
        'ventas_por_usuario' => 'Ventas por empleado',
        'gastos'             => 'Gastos y salidas (cada uno con su concepto)',
        'entradas'           => 'Otras entradas (ingresos, abonos, anticipos)',
        'productos'          => 'Productos vendidos',
        'efectivo'           => 'Debe haber en caja, contado y diferencia',
        'conteo'             => 'Conteo de billetes y monedas',
        'observaciones'      => 'Observaciones del cierre',
        'firmas'             => 'Líneas de firma (cajero y supervisor)',
    ];

    protected $casts = [
        'ticket_cierre'                  => 'array',
        'permitir_stock_negativo'        => 'boolean',
        'descuento_max_sin_autorizacion' => 'decimal:2',
        'cierre_ciego'                   => 'boolean',
        'aviso_cambio'                   => 'boolean',
        'ticket_por_comprar'             => 'boolean',
    ];

    /**
     * Devuelve la única fila de configuración, creándola con valores por defecto si no existe.
     */
    /** Secciones del ticket de cierre con su estado: lo guardado encima de «todas encendidas». */
    public function seccionesCierre(): array
    {
        $guardado = is_array($this->ticket_cierre) ? $this->ticket_cierre : [];
        $secciones = [];
        foreach (array_keys(self::SECCIONES_CIERRE) as $clave) {
            $secciones[$clave] = array_key_exists($clave, $guardado) ? (bool) $guardado[$clave] : true;
        }
        $secciones['por_comprar'] = (bool) $this->ticket_por_comprar;

        return $secciones;
    }

    public static function actual(): self
    {
        return static::query()->first() ?? static::create(['nombre' => 'Panadería Eduardo']);
    }
}
