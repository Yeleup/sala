<?php

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageAuthor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote the message. Until now the journal held two sides and read
 * direction as the answer; the operator writing from the WhatsApp Business
 * app on the same number is a third, and their messages are outbound just
 * like the bot's.
 *
 * Backfilling from direction is honest rather than a guess: every existing
 * outbound row was sent by us through the API, because the operator's
 * messages never reached the journal at all — that is the gap being closed.
 *
 * The unique index is partial on purpose. A bot row gets its wamid later,
 * from the delivery status event, so a plain unique index over the column
 * would fight that; operator rows carry the wamid from the start and it is
 * the only thing that identifies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_messages', function (Blueprint $table): void {
            $table->string('author', 16)->default(ChannelMessageAuthor::Bot->value)->after('direction');
        });

        DB::table('channel_messages')
            ->where('direction', ChannelDirection::Inbound->value)
            ->update(['author' => ChannelMessageAuthor::Contact->value]);

        DB::statement(sprintf(
            'create unique index channel_messages_operator_wamid_unique on channel_messages (wamid) where author = %s',
            DB::getPdo()->quote(ChannelMessageAuthor::Operator->value),
        ));
    }

    public function down(): void
    {
        DB::statement('drop index if exists channel_messages_operator_wamid_unique');

        Schema::table('channel_messages', function (Blueprint $table): void {
            $table->dropColumn('author');
        });
    }
};
