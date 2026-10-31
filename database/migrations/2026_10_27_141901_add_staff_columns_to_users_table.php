<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->nullable();
            $table->foreignId('business_id')
                ->nullable()
                ->constrained('businesses')
                ->restrictOnDelete();

            $table->unsignedBigInteger('outlet_id')->nullable();

            $table->index(['business_id', 'outlet_id'], 'users_business_outlet_index');

            $table->foreign(['business_id', 'outlet_id'], 'users_business_outlet_foreign')
                ->references(['business_id', 'id'])
                ->on('outlets')
                ->restrictOnDelete();
        });

        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_role_valid
            CHECK (
                role IS NULL
                OR role IN ('business_admin', 'cashier', 'kitchen')
            )
        ");

        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_staff_assignment_valid
            CHECK (
                (role IS NULL AND business_id IS NULL AND outlet_id IS NULL)
                OR
                (role IS NOT NULL
                    AND role = 'business_admin'
                    AND business_id IS NOT NULL
                    AND outlet_id IS NULL)
                OR
                (role IS NOT NULL
                    AND role IN ('cashier', 'kitchen')
                    AND business_id IS NOT NULL
                    AND outlet_id IS NOT NULL)
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK users_staff_assignment_valid');
        DB::statement('ALTER TABLE users DROP CHECK users_role_valid');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_business_outlet_foreign');
            $table->dropIndex('users_business_outlet_index');
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn(['role', 'outlet_id']);
        });
    }
};
