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
     * A category the AI adds on its own is new until the operator approves
     * a listing it is attached to. The column defaults to the insert time,
     * so every category that already exists — and every one the operator
     * adds — is approved; only the AI writes an explicit null.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->useCurrent()->after('name');
        });

        DB::table('categories')->whereNull('approved_at')->update(['approved_at' => DB::raw('created_at')]);
        DB::table('categories')->whereNull('approved_at')->update(['approved_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
