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

        // wrapInTransaction makes the whole thing atomic:
        // either every row is updated, or nothing is. We also
        // DELETE the old rows first because the new ones might
        // have different shapes (e.g. different vocab size).
        $this->em->wrapInTransaction(function () use ($modelId, $weights) {
            $this->em->getConnection()->executeStatement('DELETE FROM model_token_embeddings WHERE model_id = ?', [$modelId]);
            $this->em->getConnection()->executeStatement('DELETE FROM model_positional_embeddings WHERE model_id = ?', [$modelId]);
            $this->em->getConnection()->executeStatement('DELETE FROM model_attention_weights WHERE model_id = ?', [$modelId]);
            $this->em->getConnection()->executeStatement('DELETE FROM model_ffn_weights WHERE model_id = ?', [$modelId]);
            $this->em->getConnection()->executeStatement('DELETE FROM model_final_projection WHERE model_id = ?', [$modelId]);
            $this->em->getConnection()->executeStatement('DELETE FROM model_layer_norms WHERE model_id = ?', [$modelId]);

            $data = $weights->data;

            // Token embeddings: one row per (tokenId, dim).
            foreach ($data['tokenEmbed'] ?? [] as $tokId => $row) {
                foreach ($row as $dim => $value) {
                    $e = new ModelTokenEmbeddingEntity();
                    $e->modelId = $modelId;
                    $e->tokenId = (int) $tokId;
                    $e->dim = (int) $dim;
                    $e->value = (float) $value;
                    $this->em->persist($e);
                }
            }
            // Positional embeddings: one row per (position, dim).
            foreach ($data['posEmbed'] ?? [] as $pos => $row) {
                foreach ($row as $dim => $value) {
                    $e = new ModelPositionalEmbeddingEntity();
                    $e->modelId = $modelId;
                    $e->position = (int) $pos;
                    $e->dim = (int) $dim;
                    $e->value = (float) $value;
                    $this->em->persist($e);
                }
            }
            // Attention weights: one row per (layer, matrix, row, col).
            foreach ($data['attn'] ?? [] as $layer => $attn) {
                foreach (['wq', 'wk', 'wv', 'wo'] as $m) {
                    foreach ($attn[$m] ?? [] as $r => $row) {
                        foreach ($row as $c => $value) {
                            $e = new ModelAttentionWeightEntity();
                            $e->modelId = $modelId;
                            $e->layer = (int) $layer;
                            $e->matrix = $m;
                            $e->row = (int) $r;
                            $e->col = (int) $c;
                            $e->value = (float) $value;
                            $this->em->persist($e);
                        }
                    }
                }
            }
            // FFN weights: b1 and b2 are vectors (no col index);
            // w1 and w2 are 2D matrices.
            foreach ($data['ffn'] ?? [] as $layer => $ffn) {
                foreach (['w1', 'b1', 'w2', 'b2'] as $m) {
                    foreach ($ffn[$m] ?? [] as $r => $row) {
                        if (!\is_array($row)) {
                            // Bias: one number per row, no column.
                            $e = new ModelFfnWeightEntity();
                            $e->modelId = $modelId;
                            $e->layer = (int) $layer;
                            $e->matrix = $m;
                            $e->row = (int) $r;
                            $e->col = 0;
                            $e->value = (float) $row;
                            $this->em->persist($e);

                            continue;
                        }
                        foreach ($row as $c => $value) {
                            $e = new ModelFfnWeightEntity();
                            $e->modelId = $modelId;
                            $e->layer = (int) $layer;
                            $e->matrix = $m;
                            $e->row = (int) $r;
                            $e->col = (int) $c;
                            $e->value = (float) $value;
                            $this->em->persist($e);
                        }
                    }
                }
            }
            // LayerNorm gamma/beta: one row per (layer, dim).
            foreach (['lnAttnGamma', 'lnAttnBeta', 'lnFfnGamma', 'lnFfnBeta'] as $which) {
                foreach ($data[$which] ?? [] as $layer => $vec) {
                    foreach ($vec as $dim => $value) {
                        $e = new ModelLayerNormEntity();
                        $e->modelId = $modelId;
                        $e->layer = (int) $layer;
                        $e->which = $which;
                        $e->dim = (int) $dim;
                        $e->value = (float) $value;
                        $this->em->persist($e);
                    }
                }
            }
            // Final projection: one row per (row, col).
            foreach ($data['final'] ?? [] as $r => $row) {
                foreach ($row as $c => $value) {
                    $e = new ModelFinalProjectionEntity();
                    $e->modelId = $modelId;
                    $e->row = (int) $r;
                    $e->col = (int) $c;
                    $e->value = (float) $value;
                    $this->em->persist($e);
                }
            }
            $this->em->flush();
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
        // We need the model config to know the right shapes for
        // the empty matrices we'll fill in below.
        $cfg = new ModelConfig(
            $entity->dModel,
            $entity->numHeads,
            $entity->numLayers,
            $entity->dFf,
            $entity->maxSeqLen,
            $entity->vocabSize,
        );

        // Start with all-zeros everywhere, then fill in the
        // saved values on top.
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

        // Walk every "weight row" table and put the numbers back
        // into the right slot in the nested array.
        foreach ($this->em->getRepository(ModelTokenEmbeddingEntity::class)->findBy(['modelId' => $modelId]) as $e) {
            $data['tokenEmbed'][$e->tokenId][$e->dim] = $e->value;
        }
        foreach ($this->em->getRepository(ModelPositionalEmbeddingEntity::class)->findBy(['modelId' => $modelId]) as $e) {
            $data['posEmbed'][$e->position][$e->dim] = $e->value;
        }
        foreach ($this->em->getRepository(ModelAttentionWeightEntity::class)->findBy(['modelId' => $modelId]) as $e) {
            $data['attn'][$e->layer][$e->matrix][$e->row][$e->col] = $e->value;
        }
        foreach ($this->em->getRepository(ModelFfnWeightEntity::class)->findBy(['modelId' => $modelId]) as $e) {
            if ($e->matrix === 'b1' || $e->matrix === 'b2') {
                $data['ffn'][$e->layer][$e->matrix][$e->row] = $e->value;
                continue;
            }
            $data['ffn'][$e->layer][$e->matrix][$e->row][$e->col] = $e->value;
        }
        foreach ($this->em->getRepository(ModelLayerNormEntity::class)->findBy(['modelId' => $modelId]) as $e) {
            $data[$e->which][$e->layer][$e->dim] = $e->value;
        }
        foreach ($this->em->getRepository(ModelFinalProjectionEntity::class)->findBy(['modelId' => $modelId]) as $e) {
            $data['final'][$e->row][$e->col] = $e->value;
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
