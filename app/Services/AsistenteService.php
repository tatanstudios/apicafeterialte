<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asistente de consultas con la API de OpenAI.
 *
 * El modelo NUNCA toca la base de datos ni escribe SQL: solo puede pedir las
 * "herramientas" de abajo (los mismos reportes de la pantalla Reportes y unas
 * consultas fijas de solo lectura). Laravel las ejecuta con sus validaciones y
 * le devuelve el resultado en JSON para que lo explique.
 */
class AsistenteService
{
    private const MAX_VUELTAS = 6;   // llamadas a herramientas por pregunta
    private const MAX_FILAS = 60;    // filas de cada reporte que se mandan al modelo

    /** @param array<int, array{role: string, content: string}> $mensajes */
    public static function responder(array $mensajes): array
    {
        $clave = config('services.openai.key');
        if (!$clave) {
            throw new NegocioException('Falta configurar OPENAI_API_KEY en el servidor');
        }

        $input = [['role' => 'developer', 'content' => self::instrucciones()], ...$mensajes];
        $usados = [];
        $sinRespuesta = [];   // lo que el usuario pidió y ninguna herramienta tiene
        $tokens = 0;

        for ($vuelta = 0; $vuelta < self::MAX_VUELTAS; $vuelta++) {
            $respuesta = Http::withToken($clave)->timeout(90)->acceptJson()
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('services.openai.model'),
                    'input' => $input,
                    'tools' => self::herramientas(),
                    'store' => false,
                ]);

            if ($respuesta->failed()) {
                Log::warning('Asistente: error de OpenAI', ['status' => $respuesta->status(), 'body' => $respuesta->json('error')]);
                throw new NegocioException(match ($respuesta->status()) {
                    401     => 'La API key de OpenAI no es válida',
                    429     => 'Se acabó el saldo de OpenAI o hay demasiadas consultas; intenta en un momento',
                    404     => 'El modelo «' . config('services.openai.model') . '» no existe en OpenAI; revisa OPENAI_MODEL',
                    default => 'El asistente no respondió (' . $respuesta->status() . '); intenta de nuevo',
                });
            }

            $salida = $respuesta->json('output') ?? [];
            $tokens += (int) $respuesta->json('usage.total_tokens', 0);
            $llamadas = array_values(array_filter($salida, fn ($o) => ($o['type'] ?? '') === 'function_call'));

            if (!$llamadas) {
                $texto = collect($salida)->where('type', 'message')
                    ->flatMap(fn ($m) => $m['content'] ?? [])->where('type', 'output_text')->pluck('text')->implode("\n");

                return ['respuesta' => trim($texto) ?: 'No tengo una respuesta para eso.', 'consultas' => $usados,
                    'sin_respuesta' => $sinRespuesta, 'tokens' => $tokens];
            }

            // Se devuelve al modelo todo lo que generó (incluido su razonamiento) y el resultado de cada llamada
            foreach ($salida as $item) {
                $input[] = $item;
            }
            foreach ($llamadas as $llamada) {
                $args = json_decode($llamada['arguments'] ?? '{}', true) ?: [];
                if ($llamada['name'] === 'sin_datos') {
                    $sinRespuesta[] = [
                        'pregunta' => mb_substr((string) ($args['pregunta'] ?? ''), 0, 500),
                        'falta'    => mb_substr((string) ($args['que_falta'] ?? ''), 0, 500),
                    ];
                    $resultado = ['anotado' => true];
                } else {
                    $usados[] = ['herramienta' => $llamada['name'], 'argumentos' => $args];
                    $resultado = self::ejecutar($llamada['name'], $args);
                }
                $input[] = [
                    'type'    => 'function_call_output',
                    'call_id' => $llamada['call_id'],
                    'output'  => json_encode($resultado, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return ['respuesta' => 'La pregunta necesitó demasiadas consultas. Intenta hacerla más concreta (por ejemplo, con un periodo).', 'consultas' => $usados,
            'sin_respuesta' => $sinRespuesta, 'tokens' => $tokens];
    }

    private static function instrucciones(): string
    {
        $hoy = today();

        return "Eres el asistente del dueño de Panadería Eduardo, una panadería/cafetería en El Salvador. "
            . "Hoy es {$hoy->toDateString()} ({$hoy->locale('es')->dayName}). Moneda: dólares (USD). No se cobra IVA. "
            . "Responde en español, claro y breve, con cifras concretas y en viñetas cuando ayude. "
            . "Formato: texto simple, viñetas con '- ' y **negrita** para lo importante; no uses tablas ni encabezados #. "
            . "Para cualquier dato del negocio usa SIEMPRE las herramientas; nunca inventes ni estimes números que no te dieron. "
            . "Si ninguna herramienta tiene lo que piden (o solo lo tiene en parte), llama a sin_datos UNA vez y luego dile al usuario, "
            . "en una frase, que ese dato todavía no está disponible en el asistente y que quedó anotado para agregarlo; si puedes, "
            . "ofrece lo más cercano que sí tienes. Si una herramienta devuelve 'error', NO llames a sin_datos: explica el error al usuario. "
            . "Si la herramienta responde bien pero con ceros o listas vacías, eso es un dato real (no hubo movimientos): dilo así. Nunca uses sin_datos para preguntas que no son del negocio: a esas responde que solo "
            . "ayudas con datos de la panadería. Puedes llamar varias herramientas para comparar periodos "
            . "(por ejemplo esta semana contra la anterior). Cuando pregunten por mejoras o pérdidas, revisa utilidad, mermas, gastos, "
            . "productos más y menos vendidos, ventas por hora y cierres de caja, y da recomendaciones prácticas basadas en esos números. "
            . "'Semana' es de lunes a domingo; si no dicen periodo, usa el mes actual. Los costos y la utilidad son estimados según el costo registrado de cada producto.";
    }

    private static function herramientas(): array
    {
        $catalogo = collect(ReportesService::REPORTES)
            ->map(fn ($r, $tipo) => "{$tipo}: {$r['titulo']}" . ($r['fechas'] ? '' : ' (sin fechas, situación actual)'))
            ->implode('; ');

        return [
            [
                'type'        => 'function',
                'strict'      => false,   // con strict OpenAI obliga a mandar todos los campos y el modelo inventa filtros
                'name'        => 'reporte',
                'description' => "Genera un reporte del sistema. Tipos disponibles: {$catalogo}. "
                    . 'kardex necesita producto_id (búscalo antes con buscar_producto). Máximo un año por consulta.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'tipo'         => ['type' => 'string', 'enum' => array_keys(ReportesService::REPORTES)],
                        'desde'        => ['type' => 'string', 'description' => 'Fecha inicial YYYY-MM-DD'],
                        'hasta'        => ['type' => 'string', 'description' => 'Fecha final YYYY-MM-DD'],
                        'producto_id'  => ['type' => 'integer', 'description' => 'Solo para kardex'],
                        'clase'        => ['type' => 'string', 'enum' => ['producto_terminado', 'materia_prima', 'reventa', 'empaque'],
                            'description' => 'Solo para inventario_valorizado y solo si el usuario pide una clase; omítelo para ver todo'],
                        'motivo'       => ['type' => 'string', 'enum' => ['vencido', 'quemado', 'danado', 'otro'],
                            'description' => 'Solo para mermas y solo si el usuario pide un motivo; omítelo para ver todas'],
                    ],
                    'required'   => ['tipo'],
                ],
            ],
            [
                'type'        => 'function',
                'strict'      => false,   // con strict OpenAI obliga a mandar todos los campos y el modelo inventa filtros
                'name'        => 'resumen_hoy',
                'description' => 'Resumen del día: ventas de hoy contra ayer, ticket promedio, lo más vendido hoy, ventas por hora, '
                    . 'alertas (stock bajo, pedidos especiales de hoy o atrasados, turnos abiertos), totales del mes y cartera.',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'type'        => 'function',
                'strict'      => false,   // con strict OpenAI obliga a mandar todos los campos y el modelo inventa filtros
                'name'        => 'productos_bajo_minimo',
                'description' => 'Lista de productos e insumos con existencia igual o menor a su mínimo (lo que hay que comprar o producir).',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'type'        => 'function',
                'strict'      => false,
                'name'        => 'existencias',
                'description' => 'Existencia actual de productos e insumos activos que llevan inventario, filtrando por cantidad '
                    . '(por ejemplo "3 o menos" = maximo 3, "sin existencia" = maximo 0), por clase o por nombre. '
                    . 'Ordenado de menor a mayor existencia; incluye el mínimo configurado y la unidad.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'maximo' => ['type' => 'number', 'description' => 'Existencia menor o igual a este número'],
                        'minimo' => ['type' => 'number', 'description' => 'Existencia mayor o igual a este número'],
                        'clase'  => ['type' => 'string', 'enum' => ['producto_terminado', 'materia_prima', 'reventa', 'empaque'],
                            'description' => 'Solo si el usuario pide un tipo; omítelo para ver todo'],
                        'nombre' => ['type' => 'string', 'description' => 'Parte del nombre, solo si el usuario lo pide'],
                    ],
                ],
            ],
            [
                'type'        => 'function',
                'strict'      => false,
                'name'        => 'comparar_periodos',
                'description' => 'Compara dos periodos en una sola llamada: venta neta, tickets, ticket promedio, utilidad bruta, '
                    . 'mermas, gastos, consumo interno y resultado, con la diferencia y el % de cambio. A es el periodo actual y B el anterior.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'desde_a' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'hasta_a' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'desde_b' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'hasta_b' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required'   => ['desde_a', 'hasta_a', 'desde_b', 'hasta_b'],
                ],
            ],
            [
                'type'        => 'function',
                'strict'      => false,
                'name'        => 'ventas_de_producto',
                'description' => 'Ventas de UN producto día por día: cantidad, venta neta, costo y utilidad (sin devoluciones ni consumo interno). '
                    . 'Busca antes su id con buscar_producto.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'producto_id' => ['type' => 'integer'],
                        'desde'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'hasta'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required'   => ['producto_id'],
                ],
            ],
            [
                'type'        => 'function',
                'strict'      => false,
                'name'        => 'diferencias_caja_por_usuario',
                'description' => 'Faltantes y sobrantes de los cierres de caja agrupados por el usuario que cerró (hizo el arqueo), '
                    . 'con la lista de turnos que tuvieron diferencia.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'desde' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'hasta' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    ],
                ],
            ],
            [
                'type'        => 'function',
                'strict'      => false,
                'name'        => 'clientes_que_mas_compran',
                'description' => 'Clientes registrados que más compraron en el periodo (ventas con cliente asignado): total neto, '
                    . 'número de compras, última compra y lo que deben hoy. Para saber quién debe más usa el reporte cartera_clientes.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'desde' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'hasta' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    ],
                ],
            ],
            [
                'type'        => 'function',
                'strict'      => false,
                'name'        => 'sin_datos',
                'description' => 'Anota una pregunta del negocio que ninguna otra herramienta puede responder, para que '
                    . 'se agregue esa consulta al sistema después.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'pregunta'  => ['type' => 'string', 'description' => 'La pregunta del usuario, resumida'],
                        'que_falta' => ['type' => 'string', 'description' => 'Qué dato o consulta haría falta para responderla'],
                    ],
                    'required'   => ['pregunta', 'que_falta'],
                ],
            ],
            [
                'type'        => 'function',
                'strict'      => false,   // con strict OpenAI obliga a mandar todos los campos y el modelo inventa filtros
                'name'        => 'buscar_producto',
                'description' => 'Busca productos o insumos por nombre y devuelve su id, clase, precio, costo y existencia.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['nombre' => ['type' => 'string']],
                    'required'   => ['nombre'],
                ],
            ],
        ];
    }

    private static function ejecutar(string $nombre, array $args): array
    {
        try {
            return match ($nombre) {
                'reporte'               => self::reporte($args),
                'resumen_hoy'           => ReportesService::panel(true),
                'productos_bajo_minimo' => self::bajoMinimo(),
                'existencias'           => self::existencias($args),
                'buscar_producto'       => self::buscarProducto((string) ($args['nombre'] ?? '')),
                'comparar_periodos'     => self::compararPeriodos($args),
                'ventas_de_producto'    => self::ventasDeProducto($args),
                'diferencias_caja_por_usuario' => self::diferenciasCaja(...self::rango($args)),
                'clientes_que_mas_compran'     => self::clientesQueMasCompran(...self::rango($args)),
                default                 => ['error' => "Herramienta desconocida: {$nombre}"],
            };
        } catch (NegocioException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('Asistente: falló la herramienta', ['herramienta' => $nombre, 'args' => $args, 'error' => $e->getMessage()]);
            return ['error' => 'No se pudo obtener ese dato'];
        }
    }

    private static function reporte(array $args): array
    {
        // Cada filtro solo se aplica al reporte que lo usa: el modelo a veces rellena
        // parámetros que nadie pidió y eso recortaría el resultado sin avisar
        $tipo = (string) ($args['tipo'] ?? '');
        $permitidos = ['desde', 'hasta', ...match ($tipo) {
            'kardex'                => ['producto_id'],
            'inventario_valorizado' => ['clase'],
            'mermas'                => ['motivo'],
            default                 => [],
        }];
        $filtros = array_filter(array_intersect_key($args, array_flip($permitidos)), fn ($v) => $v !== null && $v !== '' && $v !== 0);
        $reporte = ReportesService::generar($tipo, $filtros);

        // Se manda solo lo que sirve para responder: sin gráfica y con un tope de filas
        $filas = $reporte['filas'] ?? [];
        unset($reporte['grafica'], $reporte['columnas']);
        $reporte['filas'] = array_slice(is_array($filas) ? $filas : collect($filas)->all(), 0, self::MAX_FILAS);
        if (count($filas) > self::MAX_FILAS) {
            $reporte['nota'] = 'Se muestran las primeras ' . self::MAX_FILAS . ' de ' . count($filas) . ' filas; los totales sí incluyen todo.';
        }

        return $reporte;
    }

    private static function existencias(array $args): array
    {
        $numero = fn ($v) => is_numeric($v) ? (float) $v : null;
        $maximo = $numero($args['maximo'] ?? null);
        $minimo = $numero($args['minimo'] ?? null);
        $clase = in_array($args['clase'] ?? null, ['producto_terminado', 'materia_prima', 'reventa', 'empaque'], true) ? $args['clase'] : null;
        $nombre = trim((string) ($args['nombre'] ?? ''));

        $consulta = DB::table('productos as p')
            ->leftJoin('inventario as i', 'i.producto_id', '=', 'p.id')
            ->leftJoin('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('p.activo', true)->where('p.controla_stock', true)
            ->when($maximo !== null, fn ($q) => $q->whereRaw('COALESCE(i.stock_actual, 0) <= ?', [$maximo]))
            ->when($minimo !== null, fn ($q) => $q->whereRaw('COALESCE(i.stock_actual, 0) >= ?', [$minimo]))
            ->when($clase, fn ($q) => $q->where('p.clase', $clase))
            ->when($nombre !== '', fn ($q) => $q->where('p.nombre', 'like', "%{$nombre}%"));

        $total = (clone $consulta)->count();
        $filas = $consulta->orderByRaw('COALESCE(i.stock_actual, 0)')->orderBy('p.nombre')->limit(150)
            ->get(['p.nombre', 'p.clase', 'u.abreviatura as unidad', 'i.stock_actual', 'i.stock_minimo'])
            ->map(fn ($r) => [
                'producto'  => $r->nombre,
                'clase'     => $r->clase,
                'existencia' => round((float) $r->stock_actual, 3),
                'unidad'    => $r->unidad,
                'minimo'    => round((float) $r->stock_minimo, 3),
            ])->all();

        return [
            'total'     => $total,
            'productos' => $filas,
            'nota'      => $total > 150 ? "Se muestran 150 de {$total}; pide un filtro más específico." : null,
        ];
    }

    private static function bajoMinimo(): array
    {
        return DB::table('inventario as i')->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->leftJoin('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('p.activo', true)->where('p.controla_stock', true)->where('i.stock_minimo', '>', 0)
            ->whereColumn('i.stock_actual', '<=', 'i.stock_minimo')
            ->orderBy('p.nombre')->limit(100)
            ->get(['p.id', 'p.nombre', 'p.clase', 'u.nombre as unidad', 'i.stock_actual', 'i.stock_minimo'])
            ->map(fn ($r) => [...(array) $r, 'stock_actual' => round((float) $r->stock_actual, 3), 'stock_minimo' => round((float) $r->stock_minimo, 3)])
            ->all();
    }

    private static function buscarProducto(string $nombre): array
    {
        if (mb_strlen(trim($nombre)) < 2) {
            return ['error' => 'Escribe al menos 2 letras del nombre'];
        }

        return DB::table('productos as p')->leftJoin('inventario as i', 'i.producto_id', '=', 'p.id')
            ->where('p.nombre', 'like', '%' . trim($nombre) . '%')
            ->orderByDesc('p.activo')->orderBy('p.nombre')->limit(15)
            ->get(['p.id', 'p.nombre', 'p.clase', 'p.activo', 'p.precio_venta', 'p.costo_unitario', 'i.stock_actual'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Periodo pedido (por defecto el mes actual), con el mismo tope de un año que los reportes. */
    private static function rango(array $args, string $sufijo = ''): array
    {
        $hasta = Carbon::parse($args["hasta{$sufijo}"] ?? today())->endOfDay();
        $desde = Carbon::parse($args["desde{$sufijo}"] ?? $hasta->copy()->startOfMonth())->startOfDay();
        if ($desde->gt($hasta)) {
            throw new NegocioException('La fecha inicial no puede ser mayor que la final');
        }
        if ($desde->diffInDays($hasta) > 366) {
            throw new NegocioException('El periodo no puede ser mayor a un año');
        }

        return [$desde, $hasta];
    }

    private static function compararPeriodos(array $args): array
    {
        $resumen = function (Carbon $desde, Carbon $hasta) {
            $filtros = ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()];
            $v = ReportesService::generar('ventas_diarias', $filtros)['totales'];
            $r = ReportesService::generar('resultados', $filtros)['totales'];

            return [
                'periodo'         => "{$filtros['desde']} a {$filtros['hasta']}",
                'venta_neta'      => $v['neto'],
                'tickets'         => $v['tickets'],
                'ticket_promedio' => $v['tickets'] > 0 ? round($v['ventas'] / $v['tickets'], 2) : 0,
                'devoluciones'    => $v['devoluciones'],
                'descuentos'      => $v['descuentos'],
                'utilidad_bruta'  => $r['utilidad_bruta'],
                'mermas'          => $r['mermas'],
                'consumo_interno' => $r['consumo_interno'],
                'gastos'          => $r['gastos'],
                'resultado'       => $r['resultado'],
            ];
        };

        $a = $resumen(...self::rango($args, '_a'));
        $b = $resumen(...self::rango($args, '_b'));

        $cambios = [];
        foreach ($a as $clave => $valor) {
            if ($clave === 'periodo') continue;
            $cambios[$clave] = [
                'diferencia' => round($valor - $b[$clave], 2),
                'porcentaje' => $b[$clave] != 0 ? round(($valor - $b[$clave]) / abs($b[$clave]) * 100, 1) : null,
            ];
        }

        return ['periodo_a' => $a, 'periodo_b' => $b, 'cambio_a_contra_b' => $cambios];
    }

    private static function ventasDeProducto(array $args): array
    {
        $producto = DB::table('productos')->where('id', (int) ($args['producto_id'] ?? 0))->first(['id', 'nombre']);
        if (!$producto) {
            return ['error' => 'No existe ese producto; búscalo con buscar_producto'];
        }
        [$desde, $hasta] = self::rango($args);

        // Lo cobrado por línea con el descuento general repartido y sin lo devuelto (igual que «Productos más vendidos»)
        $neto = '(vd.subtotal * CASE WHEN v.subtotal > 0 THEN (v.subtotal - v.descuento_global) / v.subtotal ELSE 1 END
                  * (vd.cantidad - vd.cantidad_devuelta) / vd.cantidad)';

        $dias = DB::table('ventas_detalle as vd')->join('ventas as v', 'v.id', '=', 'vd.venta_id')
            ->where('vd.producto_id', $producto->id)->where('vd.consumo_interno', false)
            ->where('v.estado', '!=', 'anulada')->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->groupBy(DB::raw('DATE(v.fecha_venta)'))
            ->selectRaw("DATE(v.fecha_venta) as fecha, SUM(vd.cantidad - vd.cantidad_devuelta) as cantidad, SUM($neto) as venta,
                SUM((vd.cantidad - vd.cantidad_devuelta) * vd.costo_unitario) as costo")
            ->orderBy('fecha')->get()
            ->map(fn ($r) => [
                'fecha'    => $r->fecha,
                'cantidad' => round((float) $r->cantidad, 3),
                'venta'    => round((float) $r->venta, 2),
                'costo'    => round((float) $r->costo, 2),
                'utilidad' => round((float) $r->venta - (float) $r->costo, 2),
            ]);

        return [
            'producto' => $producto->nombre,
            'periodo'  => "{$desde->toDateString()} a {$hasta->toDateString()}",
            'dias_con_venta' => $dias->count(),
            'totales'  => [
                'cantidad' => round($dias->sum('cantidad'), 3),
                'venta'    => round($dias->sum('venta'), 2),
                'utilidad' => round($dias->sum('utilidad'), 2),
            ],
            'por_dia'  => $dias->take(self::MAX_FILAS)->values()->all(),
        ];
    }

    private static function diferenciasCaja(Carbon $desde, Carbon $hasta): array
    {
        $turnos = DB::table('turnos_caja as t')
            ->join('cajas as c', 'c.id', '=', 't.caja_id')
            ->leftJoin('administradores as a', 'a.id', '=', 't.usuario_cierre_id')
            ->where('t.estado', 'cerrado')->whereBetween('t.cerrado_at', [$desde, $hasta])
            ->orderBy('t.cerrado_at')
            ->get(['t.id', 'c.nombre as caja', 'a.nombre as cerro', 't.cerrado_at', 't.efectivo_esperado', 't.efectivo_contado', 't.diferencia']);

        $porUsuario = $turnos->groupBy(fn ($t) => $t->cerro ?? 'Sin usuario')->map(function ($ts, $usuario) {
            $dif = $ts->map(fn ($t) => (float) $t->diferencia);
            return [
                'usuario'          => $usuario,
                'turnos_cerrados'  => $ts->count(),
                'turnos_faltante'  => $dif->filter(fn ($d) => $d < 0)->count(),
                'turnos_sobrante'  => $dif->filter(fn ($d) => $d > 0)->count(),
                'total_faltantes'  => round($dif->filter(fn ($d) => $d < 0)->sum(), 2),
                'total_sobrantes'  => round($dif->filter(fn ($d) => $d > 0)->sum(), 2),
                'neto'             => round($dif->sum(), 2),
            ];
        })->sortBy('total_faltantes')->values();

        return [
            'periodo'     => "{$desde->toDateString()} a {$hasta->toDateString()}",
            'nota'        => 'diferencia = contado − esperado: negativo es faltante, positivo sobrante',
            'por_usuario' => $porUsuario->all(),
            'turnos_con_diferencia' => $turnos->filter(fn ($t) => abs((float) $t->diferencia) >= 0.01)->take(self::MAX_FILAS)
                ->map(fn ($t) => [
                    'turno' => $t->id, 'caja' => $t->caja, 'cerro' => $t->cerro,
                    'cierre' => Carbon::parse($t->cerrado_at)->format('Y-m-d H:i'),
                    'esperado' => (float) $t->efectivo_esperado, 'contado' => (float) $t->efectivo_contado, 'diferencia' => (float) $t->diferencia,
                ])->values()->all(),
        ];
    }

    private static function clientesQueMasCompran(Carbon $desde, Carbon $hasta): array
    {
        $devuelto = DB::table('devoluciones')->groupBy('venta_id')->selectRaw('venta_id, SUM(total) as total');
        $deuda = DB::table('cuentas_cobrar')->where('estado', 'pendiente')->groupBy('cliente_id')->selectRaw('cliente_id, SUM(saldo) as saldo');

        return DB::table('ventas as v')->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->leftJoinSub($devuelto, 'd', 'd.venta_id', '=', 'v.id')
            ->leftJoinSub($deuda, 'cc', 'cc.cliente_id', '=', 'c.id')
            ->where('v.estado', '!=', 'anulada')->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->groupBy('c.id', 'c.nombre', 'cc.saldo')
            ->selectRaw('c.nombre as cliente, COUNT(*) as compras, SUM(v.total - COALESCE(d.total, 0)) as total_neto,
                MAX(v.fecha_venta) as ultima_compra, COALESCE(cc.saldo, 0) as debe_hoy')
            ->orderByDesc('total_neto')->limit(20)->get()
            ->map(fn ($r) => [
                'cliente'       => $r->cliente,
                'compras'       => (int) $r->compras,
                'total_neto'    => round((float) $r->total_neto, 2),
                'promedio'      => $r->compras > 0 ? round((float) $r->total_neto / $r->compras, 2) : 0,
                'ultima_compra' => substr((string) $r->ultima_compra, 0, 10),
                'debe_hoy'      => round((float) $r->debe_hoy, 2),
            ])->all();
    }
}
