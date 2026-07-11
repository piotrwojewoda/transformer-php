<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Repository;

use App\LanguageModel\Domain\Model\ModelId;

/**
 * Per-parameter Adam state (m, v) is persisted in a dedicated table so that
 * training can resume across worker restarts.
 *
 * The state is keyed by (modelId, path, row, col) and stores m and v as
 * floats. The path is a dotted path like "attn.0.wq".
 */
// Per-parameter Adam state (m, v) is persisted in a dedicated
// table so that training can resume across worker restarts.
//
// The state is keyed by (modelId, path, row, col) and stores m
// and v as floats. The path is a dotted path like "attn.0.wq".
interface AdamStateRepository
{
    /**
     * Replace ALL Adam state for a model with the given map.
     * The map key is "path:row:col", the value is the (m, v)
     // pair for that single weight element.
     *
     * @param array<string, array{0: int, 1: int, m: float, v: float}> $state
     *   Flat key: "path:row:col", value: row, col, m, v
     */
    public function saveState(ModelId $modelId, array $state): void;

    /**
     * Load Adam state for a model. Returns a map keyed by
     // "path:row:col" with (m, v) values.
     *
     * @return array<string, array{m: float, v: float}>
     *   Flat key: "path:row:col", value: m, v
     */
    public function loadState(ModelId $modelId): array;

    /**
     * Forget ALL Adam state for a model. Used when starting
     // training from scratch.
     */
    public function clearState(ModelId $modelId): void;
}
