<?php

namespace App\Enums;

/**
 * The kind of a listing: what the supplier offers — equipment for rent,
 * repair services, or himself as a driver/operator. The single source of
 * truth for each kind's questionnaire: which fields publication demands,
 * which fields the AI collector must extract, how many clarifications the
 * dialog may spend, and the built-in question texts.
 */
enum ListingKind: string
{
    case Rental = 'rental';
    case Repair = 'repair';
    case Driver = 'driver';

    /**
     * The kind stored on a scenario node. Null or garbage falls back to
     * rental explicitly: blocks configured before kinds existed keep
     * behaving exactly as before.
     */
    public static function fromNode(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Rental) : self::Rental;
    }

    public function label(): string
    {
        return match ($this) {
            self::Rental => 'Аренда спецтехники',
            self::Repair => 'Ремонт спецтехники',
            self::Driver => 'Водитель / машинист',
        };
    }

    /**
     * The business fields a listing of this kind must carry to go live,
     * mapped to the label the operator sees in the «чего не хватает» hint.
     *
     * @return array<string, string>
     */
    public function publicationFields(): array
    {
        return match ($this) {
            self::Rental => [
                'title' => 'название', 'category_id' => 'категория', 'description' => 'описание',
                'location_id' => 'локация', 'price' => 'цена',
            ],
            self::Repair => [
                'title' => 'название', 'person_name' => 'имя или название сервиса', 'services' => 'услуги',
                'repair_place' => 'где ремонтирует', 'location_id' => 'локация',
            ],
            self::Driver => [
                'title' => 'название', 'person_name' => 'имя', 'licence_type' => 'тип удостоверения',
                'experience_years' => 'стаж', 'location_id' => 'локация',
                'travels_to_other_cities' => 'готовность выезжать',
            ],
        };
    }

    /**
     * The extraction keys without which the AI collector keeps asking.
     *
     * @return list<string>
     */
    public function collectorRequiredFields(): array
    {
        return match ($this) {
            self::Rental => ['category', 'description', 'location_id', 'price'],
            self::Repair => ['person_name', 'services', 'repair_place', 'location_id'],
            self::Driver => ['person_name', 'machine_categories', 'licence_type',
                'experience_years', 'location_id', 'travels_to_other_cities'],
        };
    }

    /**
     * Clarification attempts before the collector gives up and hands the
     * supplier the web form: proportional to the questionnaire length.
     */
    public function maxClarifications(): int
    {
        return match ($this) {
            self::Rental => 3,
            self::Repair => 4,
            self::Driver => 6,
        };
    }

    /**
     * Whether the questionnaire demands a document photo (the driver's
     * licence) before the listing may be submitted.
     */
    public function requiresDocument(): bool
    {
        return $this === self::Driver;
    }

    /**
     * The enumerable fields collected with WhatsApp buttons instead of a
     * free-text question — a fixed 2–3 way choice types worse than it taps.
     *
     * @return array<string, array{question: string, options: array<string, string>}>
     */
    public function buttonFields(): array
    {
        return match ($this) {
            self::Rental => [],
            self::Repair => [
                'repair_place' => [
                    'question' => 'Где вы выполняете ремонт?',
                    'options' => ['own_service' => 'В своём сервисе', 'travels' => 'С выездом', 'both' => 'И так, и так'],
                ],
            ],
            self::Driver => [
                'licence_type' => [
                    'question' => 'Какое у вас удостоверение?',
                    'options' => ['driver_licence' => 'Водительское', 'tractor_operator' => 'Тракторист-машинист', 'other' => 'Другой документ'],
                ],
                'travels_to_other_cities' => [
                    'question' => 'Готовы выезжать на работу в другие города?',
                    'options' => ['yes' => 'Да', 'no' => 'Нет'],
                ],
            ],
        };
    }

    /**
     * The static fallback question per missing field — what the collector
     * asks when the model offered no clarifying question of its own.
     *
     * @return array<string, string>
     */
    public function fallbackQuestions(): array
    {
        return match ($this) {
            self::Rental => [
                'category' => 'Что именно вы предлагаете — какая техника?',
                'description' => 'Опишите чуть подробнее ваше предложение.',
                'location_id' => 'В каком городе, районе или селе это доступно?',
                'price' => 'Какая цена или тариф?',
            ],
            self::Repair => [
                'person_name' => 'Как вас зовут или как называется ваш сервис?',
                'services' => 'Какие работы вы выполняете? Например: диагностика, ремонт двигателя, гидравлика, электрика, сварочные работы.',
                'repair_place' => 'Где вы выполняете ремонт — в своём сервисе или с выездом?',
                'location_id' => 'В каком городе, районе или селе вы работаете?',
            ],
            self::Driver => [
                'person_name' => 'Как вас зовут?',
                'machine_categories' => 'На какой технике вы работаете — экскаватор, самосвал, кран?',
                'licence_type' => 'Какое у вас удостоверение — водительское или тракторист-машинист?',
                'experience_years' => 'Сколько лет вы работаете на этой технике?',
                'location_id' => 'В каком городе, районе или селе вы работаете?',
                'travels_to_other_cities' => 'Готовы ли вы выезжать на работу в другие города?',
            ],
        };
    }

    /**
     * The default greeting of the AI block — what goes out when the
     * operator left the block's own text empty.
     */
    public function greeting(): string
    {
        return match ($this) {
            self::Rental => 'Расскажите, что вы предлагаете: пришлите фото, голосовое или напишите текстом — что это, в каком городе и по какой цене.',
            self::Repair => 'Расскажите о себе: какие работы выполняете, в своём сервисе или с выездом, в каком городе, сколько стоит диагностика.',
            self::Driver => 'Расскажите о себе: на какой технике работаете, какое удостоверение, сколько лет стажа, в каком городе, готовы ли выезжать.',
        };
    }
}
