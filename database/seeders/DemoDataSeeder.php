<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
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

        Listing::factory()->count(2)->recycle($suppliers)->create();
        Listing::factory()->count(3)->recycle($suppliers)->pendingModeration()
            ->has(ListingMedia::factory()->count(2), 'media')
            ->has(ListingMedia::factory()->audio(), 'media')
            ->create();
        $published = Listing::factory()->count(4)->recycle($suppliers)->published()
            ->has(ListingMedia::factory(), 'media')
            ->create();
        Listing::factory()->recycle($suppliers)->service()->published()->create();
        Listing::factory()->recycle($suppliers)->rejected()->create();
        Listing::factory()->recycle($suppliers)->archived()->create();
        Listing::factory()->recycle($suppliers)->expired()->create();

        $customers = Contact::factory()->count(2)->withClosedSessionWindow()->create();

        CustomerRequest::factory()->count(2)->recycle($customers)->recycle($published)->create();
        CustomerRequest::factory()->recycle($customers)->recycle($published)->accepted()->create();
        CustomerRequest::factory()->recycle($customers)->recycle($published)->declined()->create();
    }
}
