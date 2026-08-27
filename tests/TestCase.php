<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Ai\Embeddings;

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

        Embeddings::fake();
    }
}
