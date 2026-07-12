<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Repository;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Model\ModelStatus;
use App\LanguageModel\Domain\Model\Weights;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\LanguageModelEntity;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\ModelAttentionWeightEntity;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\ModelFfnWeightEntity;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\ModelFinalProjectionEntity;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\ModelLayerNormEntity;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\ModelPositionalEmbeddingEntity;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\ModelTokenEmbeddingEntity;
use Doctrine\ORM\EntityManagerInterface;

// The Doctrine implementation of LanguageModelRepository.
//
// "Weights" (the big bag of numbers) are stored in separate
// tables, one row per single number. This is unusual but
// great for debugging: you can literally SELECT * FROM
// model_attention_weights WHERE model_id = ? and see every
// weight. The trade-off is that saving is slower.
final readonly class DoctrineLanguageModelRepository implements LanguageModelRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(LanguageModel $model): void
    {
        $repo = $this->em->getRepository(LanguageModelEntity::class);
        $entity = $repo->findOneBy(['uuid' => $model->id->value]);
        if ($entity === null) {
            $entity = new LanguageModelEntity();
            $entity->uuid = $model->id->value;
        }
        $entity->name = $model->name;
        $entity->dModel = $model->config->dModel;
        $entity->numHeads = $model->config->numHeads;
        $entity->numLayers = $model->config->numLayers;
        $entity->dFf = $model->config->dFf;
        $entity->maxSeqLen = $model->config->maxSeqLen;
        $entity->vocabSize = $model->config->vocabSize;
        $entity->status = $model->status()->value;
        $entity->createdAt = $model->createdAt;
        $entity->updatedAt = $model->updatedAt;
        $this->em->persist($entity);
        $this->em->flush();
    }

    public function find(ModelId $id): ?LanguageModel
    {
        $entity = $this->em->getRepository(LanguageModelEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            return null;
        }

        // false = don't load weights (they're expensive).
        return $this->toDomain($entity, false);
    }

    public function findWithWeights(ModelId $id): ?LanguageModel
    {
        $entity = $this->em->getRepository(LanguageModelEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            return null;
        }

        // true = load the weights too.
        return $this->toDomain($entity, true);
    }

    /**
     * @return list<LanguageModel>
     */
    public function all(): array
    {
        $entities = $this->em->getRepository(LanguageModelEntity::class)->findBy([], ['id' => 'DESC']);
        $out = [];
        foreach ($entities as $entity) {
            $out[] = $this->toDomain($entity, false);
        }

        return $out;
    }

    public function saveWeights(ModelId $id, Weights $weights): void
    {
        $entity = $this->em->getRepository(LanguageModelEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            throw new \RuntimeException("No language model with uuid {$id->value}.");
        }
        $modelId = (int) $entity->id;

        // Use raw SQL INSERT batching instead of Doctrine persist
        // to avoid OOM from 8M+ entity objects in UnitOfWork.
        //
        // Rows are flushed in batches of batchSize so that only
        // batchSize rows are held in PHP memory at any time.
        $conn = $this->em->getConnection();
        $this->em->wrapInTransaction(function () use ($conn, $modelId, $weights) {
            $conn->executeStatement('DELETE FROM model_token_embeddings WHERE model_id = ?', [$modelId]);
            $conn->executeStatement('DELETE FROM model_positional_embeddings WHERE model_id = ?', [$modelId]);
            $conn->executeStatement('DELETE FROM model_attention_weights WHERE model_id = ?', [$modelId]);
            $conn->executeStatement('DELETE FROM model_ffn_weights WHERE model_id = ?', [$modelId]);
            $conn->executeStatement('DELETE FROM model_final_projection WHERE model_id = ?', [$modelId]);
            $conn->executeStatement('DELETE FROM model_layer_norms WHERE model_id = ?', [$modelId]);

            $data = $weights->data;
            $batchSize = 500;

            // Accepts a buffer of rows by reference, builds one
            // INSERT with all of them, then resets the buffer.
            $flushBuffer = function (array &$buffer, string $table, array $columns) use ($conn): void {
                $n = \count($buffer);
                if ($n === 0) {
                    return;
                }
                $placeholders = [];
                $params = [];
                foreach ($buffer as $row) {
                    $ph = [];
                    foreach ($columns as $c) {
                        $ph[] = '?';
                        $params[] = $row[$c];
                    }
                    $placeholders[] = '(' . \implode(',', $ph) . ')';
                }
                $sql = 'INSERT INTO ' . $table . ' (' . \implode(',', $columns) . ') VALUES ' . \implode(',', $placeholders);
                $conn->executeStatement($sql, $params);
                $buffer = [];
            };

            // Helper: add a single row to the buffer and flush when full.
            $add = function (array &$buffer, string $table, array $columns, array $row) use ($batchSize, $flushBuffer): void {
                $buffer[] = $row;
                if (\count($buffer) >= $batchSize) {
                    $flushBuffer($buffer, $table, $columns);
                }
            };

            // Token embeddings: one row per (tokenId, dim).
            $buf = [];
            foreach ($data['tokenEmbed'] ?? [] as $tokId => $row) {
                foreach ($row as $dim => $value) {
                    $add($buf, 'model_token_embeddings', ['model_id', 'token_id', 'dim', 'value'], ['model_id' => $modelId, 'token_id' => (int) $tokId, 'dim' => (int) $dim, 'value' => (float) $value]);
                }
            }
            $flushBuffer($buf, 'model_token_embeddings', ['model_id', 'token_id', 'dim', 'value']);

            // Positional embeddings: one row per (position, dim).
            $buf = [];
            foreach ($data['posEmbed'] ?? [] as $pos => $row) {
                foreach ($row as $dim => $value) {
                    $add($buf, 'model_positional_embeddings', ['model_id', 'position', 'dim', 'value'], ['model_id' => $modelId, 'position' => (int) $pos, 'dim' => (int) $dim, 'value' => (float) $value]);
                }
            }
            $flushBuffer($buf, 'model_positional_embeddings', ['model_id', 'position', 'dim', 'value']);

            // Attention weights: one row per (layer, matrix, row, col).
            $buf = [];
            foreach ($data['attn'] ?? [] as $layer => $attn) {
                foreach (['wq', 'wk', 'wv', 'wo'] as $m) {
                    foreach ($attn[$m] ?? [] as $r => $row) {
                        foreach ($row as $c => $value) {
                            $add($buf, 'model_attention_weights', ['model_id', 'layer', 'matrix', 'row', 'col', 'value'], ['model_id' => $modelId, 'layer' => (int) $layer, 'matrix' => $m, 'row' => (int) $r, 'col' => (int) $c, 'value' => (float) $value]);
                        }
                    }
                }
            }
            $flushBuffer($buf, 'model_attention_weights', ['model_id', 'layer', 'matrix', 'row', 'col', 'value']);

            // FFN weights: b1 and b2 are vectors (no col index);
            // w1 and w2 are 2D matrices.
            $buf = [];
            foreach ($data['ffn'] ?? [] as $layer => $ffn) {
                foreach (['w1', 'b1', 'w2', 'b2'] as $m) {
                    foreach ($ffn[$m] ?? [] as $r => $row) {
                        if (!\is_array($row)) {
                            $add($buf, 'model_ffn_weights', ['model_id', 'layer', 'matrix', 'row', 'col', 'value'], ['model_id' => $modelId, 'layer' => (int) $layer, 'matrix' => $m, 'row' => (int) $r, 'col' => 0, 'value' => (float) $row]);
                            continue;
                        }
                        foreach ($row as $c => $value) {
                            $add($buf, 'model_ffn_weights', ['model_id', 'layer', 'matrix', 'row', 'col', 'value'], ['model_id' => $modelId, 'layer' => (int) $layer, 'matrix' => $m, 'row' => (int) $r, 'col' => (int) $c, 'value' => (float) $value]);
                        }
                    }
                }
            }
            $flushBuffer($buf, 'model_ffn_weights', ['model_id', 'layer', 'matrix', 'row', 'col', 'value']);

            // LayerNorm gamma/beta: one row per (layer, dim).
            $buf = [];
            foreach (['lnAttnGamma', 'lnAttnBeta', 'lnFfnGamma', 'lnFfnBeta'] as $which) {
                foreach ($data[$which] ?? [] as $layer => $vec) {
                    foreach ($vec as $dim => $value) {
                        $add($buf, 'model_layer_norms', ['model_id', 'layer', 'which_kind', 'dim', 'value'], ['model_id' => $modelId, 'layer' => (int) $layer, 'which_kind' => $which, 'dim' => (int) $dim, 'value' => (float) $value]);
                    }
                }
            }
            $flushBuffer($buf, 'model_layer_norms', ['model_id', 'layer', 'which_kind', 'dim', 'value']);

            // Final projection: one row per (row, col).
            $buf = [];
            foreach ($data['final'] ?? [] as $r => $row) {
                foreach ($row as $c => $value) {
                    $add($buf, 'model_final_projection', ['model_id', 'row', 'col', 'value'], ['model_id' => $modelId, 'row' => (int) $r, 'col' => (int) $c, 'value' => (float) $value]);
                }
            }
            $flushBuffer($buf, 'model_final_projection', ['model_id', 'row', 'col', 'value']);
        });
    }

    public function loadWeights(ModelId $id): Weights
    {
        $entity = $this->em->getRepository(LanguageModelEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            throw new \RuntimeException("No language model with uuid {$id->value}.");
        }
        $modelId = (int) $entity->id;
        $cfg = new ModelConfig(
            $entity->dModel,
            $entity->numHeads,
            $entity->numLayers,
            $entity->dFf,
            $entity->maxSeqLen,
            $entity->vocabSize,
        );

        $data = [
            'tokenEmbed' => $this->zeroMatrix($cfg->vocabSize, $cfg->dModel),
            'posEmbed' => $this->zeroMatrix($cfg->maxSeqLen, $cfg->dModel),
            'attn' => $this->zeroAttention($cfg->numLayers, $cfg->dModel),
            'ffn' => $this->zeroFfn($cfg->numLayers, $cfg->dModel, $cfg->dFf),
            'lnAttnGamma' => array_fill(0, $cfg->numLayers, array_fill(0, $cfg->dModel, 1.0)),
            'lnAttnBeta' => array_fill(0, $cfg->numLayers, array_fill(0, $cfg->dModel, 0.0)),
            'lnFfnGamma' => array_fill(0, $cfg->numLayers, array_fill(0, $cfg->dModel, 1.0)),
            'lnFfnBeta' => array_fill(0, $cfg->numLayers, array_fill(0, $cfg->dModel, 0.0)),
            'final' => $this->zeroMatrix($cfg->vocabSize, $cfg->dModel),
        ];

        $conn = $this->em->getConnection();

        $stmt = $conn->executeQuery('SELECT token_id, dim, value FROM model_token_embeddings WHERE model_id = ?', [$modelId]);
        while ($r = $stmt->fetchAssociative()) {
            $data['tokenEmbed'][(int) $r['token_id']][(int) $r['dim']] = (float) $r['value'];
        }

        $stmt = $conn->executeQuery('SELECT position, dim, value FROM model_positional_embeddings WHERE model_id = ?', [$modelId]);
        while ($r = $stmt->fetchAssociative()) {
            $data['posEmbed'][(int) $r['position']][(int) $r['dim']] = (float) $r['value'];
        }

        $stmt = $conn->executeQuery('SELECT layer, `matrix`, `row`, `col`, value FROM model_attention_weights WHERE model_id = ?', [$modelId]);
        while ($r = $stmt->fetchAssociative()) {
            $data['attn'][(int) $r['layer']][$r['matrix']][(int) $r['row']][(int) $r['col']] = (float) $r['value'];
        }

        $stmt = $conn->executeQuery('SELECT layer, `matrix`, `row`, `col`, value FROM model_ffn_weights WHERE model_id = ?', [$modelId]);
        while ($r = $stmt->fetchAssociative()) {
            if ($r['matrix'] === 'b1' || $r['matrix'] === 'b2') {
                $data['ffn'][(int) $r['layer']][$r['matrix']][(int) $r['row']] = (float) $r['value'];
                continue;
            }
            $data['ffn'][(int) $r['layer']][$r['matrix']][(int) $r['row']][(int) $r['col']] = (float) $r['value'];
        }

        $stmt = $conn->executeQuery('SELECT layer, which_kind, dim, value FROM model_layer_norms WHERE model_id = ?', [$modelId]);
        while ($r = $stmt->fetchAssociative()) {
            $data[$r['which_kind']][(int) $r['layer']][(int) $r['dim']] = (float) $r['value'];
        }

        $stmt = $conn->executeQuery('SELECT `row`, `col`, value FROM model_final_projection WHERE model_id = ?', [$modelId]);
        while ($r = $stmt->fetchAssociative()) {
            $data['final'][(int) $r['row']][(int) $r['col']] = (float) $r['value'];
        }

        return new Weights($data);
    }

    /**
     * Turn a database row back into a domain LanguageModel.
     * If $withWeights is true, also load the weight tables.
     */
    private function toDomain(LanguageModelEntity $entity, bool $withWeights): LanguageModel
    {
        $config = new ModelConfig(
            $entity->dModel,
            $entity->numHeads,
            $entity->numLayers,
            $entity->dFf,
            $entity->maxSeqLen,
            $entity->vocabSize,
        );
        $status = ModelStatus::from($entity->status);
        $model = new LanguageModel(
            ModelId::fromString($entity->uuid),
            $entity->name,
            $config,
            $status,
            $entity->createdAt,
            $entity->updatedAt,
        );
        if ($withWeights) {
            $model->setWeights($this->loadWeights($model->id));
        }

        return $model;
    }

    /**
     * Helper: build a 2D PHP array of zeros of the given shape.
     *
     * @return list<list<float>>
     */
    private function zeroMatrix(int $rows, int $cols): array
    {
        $out = [];
        for ($i = 0; $i < $rows; $i++) {
            $out[$i] = array_fill(0, $cols, 0.0);
        }

        return $out;
    }

    /**
     * Helper: build an empty attention structure for all layers.
     */
    private function zeroAttention(int $numLayers, int $dModel): array
    {
        $out = [];
        for ($l = 0; $l < $numLayers; $l++) {
            $out[$l] = [
                'wq' => $this->zeroMatrix($dModel, $dModel),
                'wk' => $this->zeroMatrix($dModel, $dModel),
                'wv' => $this->zeroMatrix($dModel, $dModel),
                'wo' => $this->zeroMatrix($dModel, $dModel),
            ];
        }

        return $out;
    }

    /**
     * Helper: build an empty FFN structure for all layers.
     */
    private function zeroFfn(int $numLayers, int $dModel, int $dFf): array
    {
        $out = [];
        for ($l = 0; $l < $numLayers; $l++) {
            $out[$l] = [
                'w1' => $this->zeroMatrix($dModel, $dFf),
                'b1' => array_fill(0, $dFf, 0.0),
                'w2' => $this->zeroMatrix($dFf, $dModel),
                'b2' => array_fill(0, $dModel, 0.0),
            ];
        }

        return $out;
    }
}
