<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The listing kind (rental / repair / driver) and the fields of the
     * repair and driver questionnaires. The 'rental' default covers the
     * existing rows — no backfill needed. The pivot holds the machines a
     * driver operates (a driver spans categories, unlike a rental).
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('kind', 16)->default('rental')->index();
            $table->string('person_name')->nullable();
            $table->text('services')->nullable();
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->string('repair_place', 16)->nullable();
            $table->boolean('travels_to_other_cities')->nullable();
            $table->string('licence_type', 32)->nullable();
            $table->timestamp('document_verified_at')->nullable();
            $table->foreignId('document_verified_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('category_listing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->unique(['listing_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_verified_by');
            $table->dropColumn([
                'kind',
                'person_name',
                'services',
                'experience_years',
                'repair_place',
                'travels_to_other_cities',
                'licence_type',
                'document_verified_at',
            ]);
        });

        Schema::dropIfExists('category_listing');
    }
};
