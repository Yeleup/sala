<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use App\Models\Location;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Подключённая компания Dereu этой инсталляции; тест должен выставить
 * config('services.dereu.external_id') в 'org_test'.
 */
function connectedDereuCompany(array $attributes = []): DereuCompany
{
    return DereuCompany::factory()->create(array_merge(['external_id' => 'org_test'], $attributes));
}

/**
 * Категория из операторского справочника (создаётся при первом обращении) —
 * для объявлений в тестах: `'category_id' => categoryNamed('Автокран')->id`.
 */
function categoryNamed(string $name): Category
{
    return Category::query()->firstOrCreate(['name' => $name]);
}

/**
 * Марка из операторского справочника (создаётся при первом обращении) —
 * для объявлений в тестах: `'brand_id' => brandNamed('Hitachi')->id`.
 */
function brandNamed(string $name): Brand
{
    return Brand::query()->firstOrCreate(['name' => $name]);
}

/**
 * Текст подсказки под полем формы. Модалку Filament в тестах не рендерит
 * в HTML компонента, поэтому подсказку читаем прямо со схемы:
 * `->assertSchemaComponentExists('name', checkComponentUsing: fn ($c) =>
 * str_contains(helperTextOf($c), '…'))`.
 */
function helperTextOf(Component $component): string
{
    $texts = [];

    foreach ($component->getChildSchemas() as $schema) {
        foreach ($schema->getFlatComponents(withHidden: true) as $child) {
            if ($child instanceof Text) {
                $texts[] = (string) $child->getContent();
            }
        }
    }

    return implode(' ', $texts);
}

/**
 * Узел справочника локаций КАТО (создаётся при первом обращении) — для
 * объявлений в тестах: `'location_id' => locationNamed('г.Шымкент')->id`.
 */
function locationNamed(string $name, ?Location $parent = null): Location
{
    return Location::query()->firstOrCreate([
        'name' => $name,
        'parent_id' => $parent?->id,
    ]);
}

/**
 * Конверт эха ровно той формы, в какой его присылает Dereu: ни `from`, ни
 * `wamid`, ни `type` наверху нет — всё внутри message_echoes.
 */
function operatorEchoEvent(array $echo = [], array $overrides = []): DereuWebhookEvent
{
    $echo = array_merge([
        'id' => 'wamid.'.Str::random(24),
        'to' => '77774258186',
        'from' => '77779555858',
        'type' => 'text',
        'text' => ['body' => 'Ваше объявление загружено! Спасибо'],
        'timestamp' => '1788866846',
    ], $echo);

    $payload = array_merge([
        'event' => 'business_app_message_echo',
        'event_id' => (string) Str::ulid(),
        'company_id' => 'co_abc123',
        'phone_number_id' => '631370540065072',
        'payload' => [
            'messaging_product' => 'whatsapp',
            'contacts' => [['wa_id' => $echo['to']]],
            'metadata' => ['display_phone_number' => '77779555858', 'phone_number_id' => '631370540065072'],
            'message_echoes' => [$echo],
        ],
    ], $overrides);

    return DereuWebhookEvent::query()->create([
        'event' => $payload['event'],
        'event_id' => $payload['event_id'],
        'dedupe_key' => 'wamid:'.$echo['id'],
        'company_id' => $payload['company_id'],
        'phone_number_id' => $payload['phone_number_id'],
        'wamid' => null,
        'payload' => $payload,
    ]);
}
