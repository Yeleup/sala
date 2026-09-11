<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * While an operator is handling a conversation themselves, the bot stops
 * answering it.
 *
 * On 27 August a supplier was shown «Что вас интересует?» five times and
 * «Не получилось обработать сообщение» three more, in exactly the minutes
 * an operator was walking him through the same chat by voice from their
 * phone. Neither could hear the other, and the person saw both.
 *
 * On the contact rather than the session: the pause has to survive a
 * dialog ending and restarting, which is precisely what happens while two
 * parties talk at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->timestamp('operator_handoff_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('operator_handoff_until');
        });
    }
};
