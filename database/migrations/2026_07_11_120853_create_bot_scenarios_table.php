<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the prototype table that exists in the dev database without a
     * migration (cascade also removes prototype bot_steps foreign keys).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop table if exists bot_scenarios cascade');
        } else {
            Schema::dropIfExists('bot_scenarios');
        }

        Schema::create('bot_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('draft_definition');
            $table->json('published_definition')->nullable();
            $table->unsignedInteger('published_version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_scenarios');
    }
};
