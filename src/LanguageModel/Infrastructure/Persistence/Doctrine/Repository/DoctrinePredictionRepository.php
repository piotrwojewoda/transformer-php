<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Repository;

use App\LanguageModel\Domain\Inference\Prediction;
use App\LanguageModel\Domain\Inference\PredictionId;
use App\LanguageModel\Domain\Inference\PredictionStatus;
use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Domain\Inference\SamplingStrategy;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Repository\PredictionRepository;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\PredictionEntity;
use Doctrine\ORM\EntityManagerInterface;

// The Doctrine implementation of PredictionRepository.
final readonly class DoctrinePredictionRepository implements PredictionRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Prediction $prediction): void
    {
        $entity = $this->em->getRepository(PredictionEntity::class)
            ->findOneBy(['uuid' => $prediction->id->value]);
        $modelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM language_models WHERE uuid = ?',
            [$prediction->modelId->value],
        );
        if ($modelRow === false) {
            throw new \RuntimeException("No model with uuid {$prediction->modelId->value}.");
        }
        $modelId = (int) $modelRow['id'];
        if ($entity === null) {
            $entity = new PredictionEntity();
            $entity->uuid = $prediction->id->value;
            $entity->createdAt = $prediction->createdAt;
        }
        $entity->modelId = $modelId;
        // Cap the prompt and generated text at 500 characters
        // so a really long generation doesn't blow up the row.
        $entity->prompt = mb_substr($prediction->prompt, 0, 500, 'UTF-8');
        $entity->generatedText = $prediction->generatedText() !== null
            ? mb_substr($prediction->generatedText(), 0, 500, 'UTF-8')
            : null;
        $entity->sampling = $prediction->sampling->strategy->value;
        $entity->topK = $prediction->sampling->topK;
        $entity->maxNewTokens = $prediction->sampling->maxNewTokens;
        $entity->status = $prediction->status()->value;
        $entity->errorMessage = $prediction->errorMessage();
        $entity->finishedAt = $prediction->finishedAt();
        $this->em->persist($entity);
        $this->em->flush();
    }

    public function find(PredictionId $id): ?Prediction
    {
        $entity = $this->em->getRepository(PredictionEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function findByModel(ModelId $modelId, int $limit = 20): array
    {
        $modelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM language_models WHERE uuid = ?',
            [$modelId->value],
        );
        if ($modelRow === false) {
            return [];
        }
        $mid = (int) $modelRow['id'];
        $entities = $this->em->getRepository(PredictionEntity::class)
            ->findBy(['modelId' => $mid], ['id' => 'DESC'], $limit);

        return array_map(fn ($e) => $this->toDomain($e), $entities);
    }

    /**
     * Turn a database row back into a Prediction aggregate.
     */
    private function toDomain(PredictionEntity $entity): Prediction
    {
        $modelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT uuid FROM language_models WHERE id = ?',
            [$entity->modelId],
        );
        $modelUuid = $modelRow['uuid'] ?? '00000000-0000-0000-0000-000000000000';
        $strategy = SamplingStrategy::from($entity->sampling);
        $sampling = new SamplingConfig($strategy, $entity->maxNewTokens, $entity->topK);

        return Prediction::reconstruct(
            PredictionId::fromString($entity->uuid),
            ModelId::fromString($modelUuid),
            $entity->prompt,
            $sampling,
            $entity->createdAt,
            PredictionStatus::from($entity->status),
            $entity->generatedText,
            $entity->finishedAt,
            $entity->errorMessage,
        );
    }
}
