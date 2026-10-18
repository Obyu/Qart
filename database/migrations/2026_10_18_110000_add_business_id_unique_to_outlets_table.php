<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * orders.(business_id, outlet_id) references this key, so the database itself
     * rejects an order whose outlet belongs to another business. MySQL only accepts
     * a foreign key that points at a PRIMARY or UNIQUE key.
     */
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->unique(['business_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'id']);
        });
    }
};
