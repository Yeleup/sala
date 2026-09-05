<?php

namespace Database\Factories;

use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\ListingStatus;
use App\Enums\RepairPlace;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            // Null by default: listings drafted before the title field
            // existed have none, and every display falls back to the
            // category name — tests opt into a title explicitly.
            'title' => null,
            'category_id' => Category::factory(),
            'description' => fake()->randomElement([
                'Автокран 25 тонн, стрела 28 м, работаем по городу и области.',
                'Экскаватор-погрузчик, копаем траншеи и котлованы.',
                'Самосвал 20 тонн, доставка сыпучих материалов.',
            ]),
            'location_id' => Location::factory(),
            'location_detail' => null,
            'price' => (fake()->numberBetween(5, 50) * 1000).' тг/ч',
            'status' => ListingStatus::Draft,
            'rejection_reason' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Every field the publication requires is filled — the state an
     * operator's draft reaches before he may put it live himself.
     */
    public function publishable(): static
    {
        return $this->state(fn (): array => ['title' => 'Аренда автокрана 25 т']);
    }

    /**
     * A repair master's questionnaire, filled. Explicit texts, no random
     * description: matcher tests must not depend on factory randomness.
     * The rental-only fields go empty — a repair has no category or price.
     */
    public function repair(): static
    {
        return $this->state(fn (): array => [
            'kind' => ListingKind::Repair,
            'person_name' => 'Сервис «Мотор»',
            'services' => 'Диагностика, ремонт двигателя, гидравлика, электрика.',
            'repair_place' => RepairPlace::Both,
            'category_id' => null,
            'description' => 'Ремонтируем спецтехнику: двигатель, гидравлика, электрика.',
            'price' => null,
        ]);
    }

    /**
     * A driver's questionnaire, filled — except the machine categories,
     * which live on the machineCategories() pivot and are attached by the
     * test itself. Explicit description, no price: a driver has none. The
     * machinery outside the dictionary stays empty — it is the exception
     * a test opts into, not the shape of a typical questionnaire.
     */
    public function driver(): static
    {
        return $this->state(fn (): array => [
            'kind' => ListingKind::Driver,
            'person_name' => 'Серик',
            'licence_type' => LicenceType::TractorOperator,
            'experience_years' => 8,
            'travels_to_other_cities' => true,
            'category_id' => null,
            'description' => 'Машинист экскаватора, стаж 8 лет.',
            'price' => null,
            'unlisted_machinery' => null,
        ]);
    }

    public function pendingModeration(): static
    {
        return $this->state(fn (): array => ['status' => ListingStatus::PendingModeration]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Published,
            'expires_at' => now()->addDays(Listing::LIFETIME_DAYS),
        ]);
    }

    /**
     * Published, but the 30-day cycle has run out: invisible to search.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Published,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Rejected,
            'rejection_reason' => 'Не указана цена — добавьте тариф.',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ListingStatus::Archived]);
    }
}
