<?php

namespace Tests\Feature;

use App\Models\CategoriaGasto;
use App\Models\MetodoPago;
use Database\Seeders\CajaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

// En los historiales por fecha el mesero ve solo lo que registró él; el admin ve todo
class HistorialPropioTest extends TestCase
{
    private function gastoExterno(string $descripcion)
    {
        return $this->postJson('/api/gastos', [
            'uuid'         => (string) Str::uuid(),
            'categoria_id' => CategoriaGasto::where('nombre', 'Gas')->value('id'),
            'descripcion'  => $descripcion,
            'monto'        => 10,
            'origen'       => 'externo',
            'metodo_pago_id' => MetodoPago::where('nombre', 'Transferencia')->value('id'), 'referencia' => 'TR-1',
        ])->assertCreated();
    }

    public function test_mesero_ve_solo_lo_suyo_y_el_admin_todo(): void
    {
        $this->seed(CajaSeeder::class);
        $this->actuarComo('mesero', ['nombre' => 'Ana']);
        $this->gastoExterno('Gas de Ana');

        $this->actuarComo('mesero', ['nombre' => 'Luis']);
        $this->gastoExterno('Gas de Luis');

        $this->getJson('/api/gastos')->assertOk()
            ->assertJsonCount(1, 'gastos')
            ->assertJsonPath('gastos.0.descripcion', 'Gas de Luis')
            ->assertJsonPath('total_monto', 10);
        $this->getJson('/api/pos/ventas')->assertOk();
        $this->getJson('/api/produccion')->assertOk();
        $this->getJson('/api/compras')->assertOk();

        $this->actuarComo('admin');
        $this->getJson('/api/gastos')->assertOk()->assertJsonCount(2, 'gastos');
    }
}
