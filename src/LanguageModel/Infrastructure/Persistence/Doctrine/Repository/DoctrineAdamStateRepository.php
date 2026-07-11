<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Repository;

use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Repository\AdamStateRepository;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\AdamStateEntity;
use Doctrine\ORM\EntityManagerInterface;

// The Doctrine implementation of AdamStateRepository.
//
// We keep Adam's (m, v) running averages in their own table so
// training can be paused and resumed by a different worker
// process without losing momentum.
final readonly class DoctrineAdamStateRepository implements AdamStateRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function saveState(ModelId $modelId, array $state): void
    {
        $internal = $this->resolveModelId($modelId);
        // Wipe and rewrite. Wipe is faster than a per-row UPSERT
        // because most epochs touch most weights.
        $this->em->wrapInTransaction(function () use ($internal, $state) {
            $this->em->getConnection()->executeStatement('DELETE FROM adam_state WHERE model_id = ?', [$internal]);
            foreach ($state as $key => $row) {
                // Keys look like "path:row:col" (the last two are
                // separated by colons; the path itself can also
                // contain colons, so we split from the right).
                [$path, $r, $c] = $this->unpackKey($key);
                $e = new AdamStateEntity();
                $e->modelId = $internal;
                $e->path = $path;
                $e->row = $r;
                $e->col = $c;
                $e->m = $row['m'] ?? 0.0;
                $e->v = $row['v'] ?? 0.0;
                $this->em->persist($e);
            }
            $this->em->flush();
        });
    }

    public function loadState(ModelId $modelId): array
    {
        $internal = $this->resolveModelId($modelId);
        $entities = $this->em->getRepository(AdamStateEntity::class)->findBy(['modelId' => $internal]);
        $out = [];
        foreach ($entities as $e) {
            // Recombine the (path, row, col) into the key the
            // Adam optimizer uses.
            $out["{$e->path}:{$e->row}:{$e->col}"] = ['m' => $e->m, 'v' => $e->v];
        }

        return $out;
    }

    public function clearState(ModelId $modelId): void
    {
        $internal = $this->resolveModelId($modelId);
        $this->em->getConnection()->executeStatement('DELETE FROM adam_state WHERE model_id = ?', [$internal]);
    }

    /**
     * Helper: turn the public ModelId (a UUID) into the numeric
     // primary key used by the database.
     */
    private function resolveModelId(ModelId $modelId): int
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM language_models WHERE uuid = ?',
            [$modelId->value],
        );
        if ($row === false) {
            throw new \RuntimeException("No model with uuid {$modelId->value}.");
        }

        return (int) $row['id'];
    }

    /**
     * Unpack a key like "attn.0.wq:3:7" into (path, row, col).
     * We split on ":" from the right because the path itself may
     // contain colons (rare, but possible).
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function unpackKey(string $key): array
    {
        $parts = \explode(':', $key);
        if (\count($parts) < 3) {
            return [$key, 0, 0];
        }
        $col = (int) array_pop($parts);
        $row = (int) array_pop($parts);
        $path = \implode(':', $parts);

        return [$path, $row, $col];
    }
}
