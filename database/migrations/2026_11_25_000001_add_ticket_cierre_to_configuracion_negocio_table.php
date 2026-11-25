<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qué secciones imprime el ticket de cierre de turno (solo lo cambia el administrador).
     * null = todas encendidas (ver ConfiguracionNegocio::SECCIONES_CIERRE).
     */
    public function up(): void
    {
        Schema::table('configuracion_negocio', function (Blueprint $table) {
            $table->json('ticket_cierre')->nullable()->after('ticket_por_comprar');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_negocio', function (Blueprint $table) {
            $table->dropColumn('ticket_cierre');
        });
    }
};
