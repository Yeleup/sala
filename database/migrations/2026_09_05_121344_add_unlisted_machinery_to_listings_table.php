<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The machinery a driver named in his own words when the operator's
     * category dictionary has no entry for it («автобус», «водовоз»).
     * The extraction schema limits machine categories to the dictionary,
     * so such an answer used to collapse into «nothing said» and the bot
     * re-asked the same question until the attempt limit. Now the words
     * are kept, the questionnaire reaches moderation, and the operator
     * adds the category and links it before publishing.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('unlisted_machinery', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('unlisted_machinery');
        });
    }
};
