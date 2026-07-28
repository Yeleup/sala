<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who stands behind a listing. An operator may now both type a listing
 * for a client and publish it himself, so the origin of a listing and the
 * author of its verdict are worth keeping.
 *
 * Existing rows keep null in both — their origin is unknown rather than
 * «from the chat», and the admin reads it that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Deliberately not backfilled: a listing that predates the
            // trail is of unknown origin, and guessing «from the chat»
            // would mislabel every listing an operator typed before it.
            $table->string('origin', 16)->nullable()->after('contact_id');
            $table->foreignId('created_by_user_id')->nullable()->after('origin')->constrained('users')->nullOnDelete();
            $table->foreignId('moderated_by_user_id')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('moderated_by_user_id');
            $table->dropColumn(['origin', 'moderated_at']);
        });
    }
};
