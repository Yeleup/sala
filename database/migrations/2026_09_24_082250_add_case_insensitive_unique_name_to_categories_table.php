<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One kind of equipment is one dictionary entry whatever its letter
     * case: the AI adds new categories from concurrent supplier dialogs, and
     * «Автобус» next to «АВТОБУС» would split one offer in two. The existing
     * unique index is case-sensitive, so the rule is enforced by an index
     * over the lowercased name. Existing duplicates are not merged silently —
     * the operator must resolve them first.
     */
    public function up(): void
    {
        $duplicates = DB::table('categories')
            ->selectRaw('lower(name) as normalized')
            ->groupByRaw('lower(name)')
            ->havingRaw('count(*) > 1')
            ->pluck('normalized');

        throw_if(
            $duplicates->isNotEmpty(),
            RuntimeException::class,
            'Categories differing only in letter case must be merged first: '.$duplicates->implode(', '),
        );

        DB::statement('create unique index categories_name_lower_unique on categories (lower(name))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index if exists categories_name_lower_unique');
    }
};
