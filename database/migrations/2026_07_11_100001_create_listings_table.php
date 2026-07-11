<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drops the prototype table that exists in the dev database without a
     * migration. Cascade also removes foreign keys left on other prototype
     * tables that reference it.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop table if exists listings cascade');
        } else {
            Schema::dropIfExists('listings');
        }

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('price')->nullable();
            $table->string('status')->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
