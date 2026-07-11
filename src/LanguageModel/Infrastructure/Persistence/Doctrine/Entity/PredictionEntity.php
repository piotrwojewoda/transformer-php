<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per text-generation request.
class PredictionEntity
{
    public ?int $id = null;
    public string $uuid = '';
    public int $modelId = 0;
    // The text the user typed. Trimmed to <= 500 chars.
    public string $prompt = '';
    public ?string $generatedText = null;
    // Sampling strategy as a string ("greedy" or "top_k").
    public string $sampling = 'greedy';
    public ?int $topK = null;
    public int $maxNewTokens = 1;
    public string $status = 'queued';
    public ?string $errorMessage = null;
    public \DateTimeImmutable $createdAt;
    public ?\DateTimeImmutable $finishedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
