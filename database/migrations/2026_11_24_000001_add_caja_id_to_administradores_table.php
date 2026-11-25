<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Caja fija del usuario: quien no tiene «caja.elegir_caja» (los meseros) solo
     * abre turno en la caja que le asignó el administrador; sin caja no puede abrir.
     */
    public function up(): void
    {
        Schema::table('administradores', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('estado')->constrained('cajas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('administradores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_id');
        });
    }
};
