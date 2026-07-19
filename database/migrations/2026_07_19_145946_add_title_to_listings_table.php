<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The listing's own display name («Аренда автокрана 25 т») shown in
     * search results, notifications and cabinets instead of the category
     * name. Nullable: pre-existing listings keep falling back to the
     * category name until edited.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('title')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
