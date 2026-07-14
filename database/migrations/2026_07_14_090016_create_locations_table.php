<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The KATO location tree (~15 600 nodes, oblast → district → rural
     * okrug → settlement). `path` is a materialized `/id/id/.../` chain so
     * a whole subtree is selectable with one LIKE; `search_name` is the
     * normalized + stemmed name the resolver matches against.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->string('name');
            $table->string('search_name')->index();
            $table->string('path')->default('')->index();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
