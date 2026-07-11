<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Repository;

use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\Domain\Training\TrainingJob;
use App\LanguageModel\Domain\Training\TrainingJobId;
use App\LanguageModel\Domain\Training\TrainingLoss;
use App\LanguageModel\Domain\Training\TrainingStatus;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\TrainingJobEntity;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\TrainingLossHistoryEntity;
use Doctrine\ORM\EntityManagerInterface;

// The Doctrine implementation of TrainingJobRepository.
final readonly class DoctrineTrainingJobRepository implements TrainingJobRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(TrainingJob $job): void
    {
        $entity = $this->em->getRepository(TrainingJobEntity::class)
            ->findOneBy(['uuid' => $job->id->value]);
        // We need the numeric id of the model, not the uuid.
        $modelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM language_models WHERE uuid = ?',
            [$job->modelId->value],
        );
        if ($modelRow === false) {
            throw new \RuntimeException("No model with uuid {$job->modelId->value}.");
        }
        $modelId = (int) $modelRow['id'];
        if ($entity === null) {
            $entity = new TrainingJobEntity();
            $entity->uuid = $job->id->value;
            $entity->createdAt = $job->createdAt;
        }
        $entity->modelId = $modelId;
        $entity->status = $job->status()->value;
        $entity->totalEpochs = $job->config->totalEpochs;
        $entity->learningRate = $job->config->learningRate;
        $entity->seqLen = $job->config->seqLen;
        $entity->batchSize = $job->config->batchSize;
        $entity->epoch = $job->epoch();
        $entity->loss = $job->lastLoss()?->value;
        $entity->startedAt = $job->startedAt();
        $entity->finishedAt = $job->finishedAt();
        $entity->errorMessage = $job->errorMessage();
        $this->em->persist($entity);
        $this->em->flush();
    }

    public function find(TrainingJobId $id): ?TrainingJob
    {
        $entity = $this->em->getRepository(TrainingJobEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function findByModel(ModelId $modelId): array
    {
        $modelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM language_models WHERE uuid = ?',
            [$modelId->value],
        );
        if ($modelRow === false) {
            return [];
        }
        $mid = (int) $modelRow['id'];
        $entities = $this->em->getRepository(TrainingJobEntity::class)->findBy(['modelId' => $mid], ['id' => 'DESC']);

        return array_map(fn ($e) => $this->toDomain($e), $entities);
    }

    /**
     * Append a new (epoch, loss) point to the loss history
     // table. If a row for the same (job, epoch) already
     // exists, we update its loss value instead of inserting.
     */
    public function recordEpoch(TrainingJobId $jobId, int $epoch, TrainingLoss $loss): void
    {
        $jobEntity = $this->em->getRepository(TrainingJobEntity::class)
            ->findOneBy(['uuid' => $jobId->value]);
        if ($jobEntity === null) {
            throw new \RuntimeException("No training job with uuid {$jobId->value}.");
        }
        $existing = $this->em->getRepository(TrainingLossHistoryEntity::class)
            ->findOneBy(['trainingJobId' => $jobEntity->id, 'epoch' => $epoch]);
        if ($existing === null) {
            $row = new TrainingLossHistoryEntity();
            $row->trainingJobId = (int) $jobEntity->id;
            $row->epoch = $epoch;
            $row->loss = $loss->value;
            $this->em->persist($row);
        }
        $existing?->setLoss($loss->value);
        // Also keep the most-recent loss denormalized on the
        // job row for quick UI display.
        $jobEntity->loss = $loss->value;
        $this->em->flush();
    }

    public function lossHistory(TrainingJobId $jobId): array
    {
        $jobEntity = $this->em->getRepository(TrainingJobEntity::class)
            ->findOneBy(['uuid' => $jobId->value]);
        if ($jobEntity === null) {
            return [];
        }
        // Read every (epoch, loss) row for this job, ordered
        // by epoch ascending (oldest first).
        $rows = $this->em->getRepository(TrainingLossHistoryEntity::class)
            ->findBy(['trainingJobId' => $jobEntity->id], ['epoch' => 'ASC']);
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['epoch' => $row->epoch, 'loss' => $row->loss];
        }

        return $out;
    }

    /**
     * Turn a database row back into a TrainingJob aggregate.
     * We use a placeholder uuid if the model has gone missing.
     */
    private function toDomain(TrainingJobEntity $entity): TrainingJob
    {
        $modelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT uuid FROM language_models WHERE id = ?',
            [$entity->modelId],
        );
        $modelUuid = $modelRow['uuid'] ?? '00000000-0000-0000-0000-000000000000';
        $config = new TrainingConfig(
            $entity->learningRate,
            $entity->totalEpochs,
            $entity->seqLen,
            $entity->batchSize,
        );

        return TrainingJob::reconstruct(
            TrainingJobId::fromString($entity->uuid),
            ModelId::fromString($modelUuid),
            $config,
            $entity->createdAt,
            TrainingStatus::from($entity->status),
            $entity->epoch,
            $entity->loss !== null ? new TrainingLoss($entity->loss) : null,
            $entity->startedAt,
            $entity->finishedAt,
            $entity->errorMessage,
        );
    }
}
