<?php

namespace Database\Seeders;

use App\Enums\ListingType;
use App\Models\Category;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Fills a local environment with contacts, listings in every lifecycle
 * status and customer requests, so the admin panel has data to browse.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $suppliers = Contact::factory()->count(3)->withOpenSessionWindow()->create();

        // A listing's type must match its category's type, so equipment
        // listings recycle only equipment categories and the service one —
        // only service categories.
        $equipmentCategories = collect(['Автокран', 'Экскаватор', 'Самосвал', 'Манипулятор', 'Бетононасос'])
            ->map(fn (string $name): Category => Category::query()->firstOrCreate(['name' => $name], ['type' => ListingType::Equipment]));
        $serviceCategories = collect(['Сварщик', 'Монтажник'])
            ->map(fn (string $name): Category => Category::query()->firstOrCreate(['name' => $name], ['type' => ListingType::Service]));

        // Real KATO nodes when the dictionary is imported (LocationSeeder
        // runs first); otherwise the listing factory makes stub nodes.
        $locations = Location::query()->whereIn('name', ['г.Шымкент', 'г.Алматы', 'г.Астана', 'г.Семей'])->get();

        Listing::factory()->count(2)->recycle($suppliers)->recycle($equipmentCategories)->recycle($locations)->create();
        Listing::factory()->count(3)->recycle($suppliers)->recycle($equipmentCategories)->recycle($locations)->pendingModeration()
            ->has(ListingMedia::factory()->count(2), 'media')
            ->has(ListingMedia::factory()->audio(), 'media')
            ->create();
        $published = Listing::factory()->count(4)->recycle($suppliers)->recycle($equipmentCategories)->recycle($locations)->published()
            ->has(ListingMedia::factory(), 'media')
            ->create();
        Listing::factory()->recycle($suppliers)->recycle($serviceCategories)->recycle($locations)->service()->published()->create();
        Listing::factory()->recycle($suppliers)->recycle($equipmentCategories)->recycle($locations)->rejected()->create();
        Listing::factory()->recycle($suppliers)->recycle($equipmentCategories)->recycle($locations)->archived()->create();
        Listing::factory()->recycle($suppliers)->recycle($equipmentCategories)->recycle($locations)->expired()->create();

        $customers = Contact::factory()->count(2)->withClosedSessionWindow()->create();

        CustomerRequest::factory()->count(2)->recycle($customers)->recycle($published)->create();
        CustomerRequest::factory()->recycle($customers)->recycle($published)->accepted()->create();
        CustomerRequest::factory()->recycle($customers)->recycle($published)->declined()->create();
    }
}
