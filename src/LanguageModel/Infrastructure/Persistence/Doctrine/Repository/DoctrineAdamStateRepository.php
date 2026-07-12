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
        $conn = $this->em->getConnection();
        $this->em->clear(AdamStateEntity::class);
        $conn->executeStatement('DELETE FROM adam_state WHERE model_id = ?', [$internal]);

        $batchSize = 500;
        $params = [];
        $placeholders = [];
        $i = 0;
        foreach ($state as $key => $row) {
            $params[] = $internal;
            $params[] = $key;
            $params[] = 0;
            $params[] = 0;
            $params[] = $row['m'] ?? 0.0;
            $params[] = $row['v'] ?? 0.0;
            $placeholders[] = '(?,?,?,?,?,?)';
            $i++;
            if ($i % $batchSize === 0) {
                $conn->executeStatement('INSERT IGNORE INTO adam_state (model_id, param_path, row_idx, col_idx, m, v) VALUES ' . \implode(',', $placeholders), $params);
                $params = [];
                $placeholders = [];
            }
        }
        if ($placeholders !== []) {
            $conn->executeStatement('INSERT IGNORE INTO adam_state (model_id, param_path, row_idx, col_idx, m, v) VALUES ' . \implode(',', $placeholders), $params);
        }
    }

    public function loadState(ModelId $modelId): array
    {
        $internal = $this->resolveModelId($modelId);
        $conn = $this->em->getConnection();
        $out = [];
        $stmt = $conn->executeQuery(
            'SELECT param_path, m, v FROM adam_state WHERE model_id = ? ORDER BY param_path',
            [$internal],
        );
        while ($row = $stmt->fetchAssociative()) {
            $out[$row['param_path']] = ['m' => (float) $row['m'], 'v' => (float) $row['v']];
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
     *
     * For 1D entries the key looks like "path:index" (1 colon).
     * For 2D entries the key looks like "path:row:col" (2 colons).
     * The path itself may contain colons, so we split from the right.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function unpackKey(string $key): array
    {
        $parts = \explode(':', $key);
        $n = \count($parts);
        if ($n === 1) {
            return [$key, 0, 0];
        }
        if ($n === 2) {
            // 1D entry: key = "path:row"
            return [$parts[0], (int) $parts[1], 0];
        }
        // 2D entry: key = "path:row:col" (path may have extra colons)
        $col = (int) array_pop($parts);
        $row = (int) array_pop($parts);
        $path = \implode(':', $parts);

        return [$path, $row, $col];
    }
}
