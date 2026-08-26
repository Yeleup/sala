<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Снимок прерванной анкеты, который ИИ-навигатор сохраняет перед тем,
     * как увести контакт с шага сбора данных в другой раздел меню — чтобы
     * вернуть анкету туда же, если контакт передумает. Колонку пока никто
     * не читает и не пишет: субстрат для последующих задач оркестратора.
     */
    public function up(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->json('paused_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->dropColumn('paused_state');
        });
    }
};
