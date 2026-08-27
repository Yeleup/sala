<?php

namespace Tests;

use App\Services\Ai\ListingEmbeddings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

abstract class TestCase extends BaseTestCase
{
    /**
     * Эмбеддинги фейкуются для всего сьюта: сохранение опубликованного
     * объявления синхронно запускает GenerateListingEmbedding, и без фейка
     * такой тест ходил бы в реальный API провайдера. Тесты со своими
     * векторами перекрывают дефолт повторным Embeddings::fake(...).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            $this->deterministicVector(...),
            $prompt->inputs,
        ));
    }

    /**
     * Единичный вектор вдоль оси, выведенной из самого текста. Дефолт
     * Embeddings::fake() строит вектор из mt_rand(), а он попадает в
     * listing_embeddings и участвует в косинусном ранжировании: сьют, где
     * половина объявлений сохраняется мимоходом, получил бы случайные
     * данные в поиске, и падения зависели бы от запаса между случайным
     * косинусом и порогом похожести — то есть от тюнинговой константы.
     * Здесь одинаковый текст всегда даёт один и тот же вектор, а разные
     * тексты почти наверняка расходятся по осям (косинус 0).
     *
     * @return array<float>
     */
    private function deterministicVector(string $input): array
    {
        $vector = array_fill(0, ListingEmbeddings::DIMENSIONS, 0.0);
        $vector[crc32($input) % ListingEmbeddings::DIMENSIONS] = 1.0;

        return $vector;
    }
}
