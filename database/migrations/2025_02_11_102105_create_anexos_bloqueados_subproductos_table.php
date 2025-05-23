<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('anexos_bloqueados_subproductos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('anexo_id');
            $table->unsignedBigInteger('subproducto_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anexos_bloqueados_subproductos');
    }
};
