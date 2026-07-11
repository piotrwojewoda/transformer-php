<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Inference;

// Which way should the model pick the next character?
// See the ModelPredictor for the actual math; here we just
// enumerate the two strategies we support.
enum SamplingStrategy: string
{
    // Greedy: always pick the character with the highest logit.
    case Greedy = 'greedy';
    // Top-K: only consider the K most-likely characters, then
    // pick one of them randomly (proportional to their logits).
    case TopK = 'top_k';
}
