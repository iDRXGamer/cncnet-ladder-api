<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('qm_queue_entries', function (Blueprint $table) {
            $table->boolean('casual')->default(false)->index();
        });

        Schema::table('qm_matches', function (Blueprint $table) {
            $table->boolean('is_casual')->default(false);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->boolean('is_casual')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qm_queue_entries', function (Blueprint $table) {
            $table->dropColumn('casual');
        });

        Schema::table('qm_matches', function (Blueprint $table) {
            $table->dropColumn('is_casual');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('is_casual');
        });
    }
};
