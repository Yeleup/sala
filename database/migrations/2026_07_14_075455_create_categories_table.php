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
     * The listing category becomes an operator-managed dictionary: a
     * `categories` table replaces the free-text `listings.category` column.
     * Existing text values are moved into the dictionary so published
     * listings keep their category.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('type')
                ->index()
                ->constrained()
                ->restrictOnDelete();
        });

        $this->moveListingCategoriesIntoDictionary();

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('category')->nullable()->after('type');
        });

        DB::table('listings')
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->eachById(function (object $listing): void {
                DB::table('listings')
                    ->where('id', $listing->id)
                    ->update(['category' => DB::table('categories')->where('id', $listing->category_id)->value('name')]);
            });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('categories');
    }

    private function moveListingCategoriesIntoDictionary(): void
    {
        $rawValues = DB::table('listings')->whereNotNull('category')->distinct()->pluck('category');

        foreach ($rawValues as $rawValue) {
            $name = trim((string) $rawValue);

            if ($name === '') {
                continue;
            }

            $categoryId = DB::table('categories')->where('name', $name)->value('id')
                ?? DB::table('categories')->insertGetId([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('listings')->where('category', $rawValue)->update(['category_id' => $categoryId]);
        }
    }
};
