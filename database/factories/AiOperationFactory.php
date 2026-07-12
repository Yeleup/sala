<?php

namespace Database\Factories;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Models\AiOperation;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiOperation>
 */
class AiOperationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation' => AiOperationType::ListingExtraction,
            'status' => AiOperationStatus::Completed,
            'contact_id' => Contact::factory(),
        ];
    }

    public function transcription(): static
    {
        return $this->state(fn (): array => ['operation' => AiOperationType::Transcription]);
    }

    public function failed(string $error = 'Provider error'): static
    {
        return $this->state(fn (): array => [
            'status' => AiOperationStatus::Failed,
            'error' => $error,
        ]);
    }
}
