<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Application\Port\PredictorPort;
use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Domain\Inference\SamplingStrategy;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Token\TokenId;
use App\LanguageModel\Domain\Token\TokenSequence;

/**
 * Autoregressive generation: encode the prompt, then repeatedly run
 * a forward pass, sample the next token, and append.
 *
 * WHAT IS "AUTOREGRESSIVE GENERATION"?
 * ------------------------------------
 * "Autoregressive" is a fancy word for "one at a time, where each
 * step depends on the previous ones". To generate text, we:
 *   1. Take the prompt ("Once upon a").
 *   2. Run the model to get probabilities for the NEXT character.
 *   3. Pick one of those characters ("time", say).
 *   4. Append it to our growing sequence.
 *   5. Repeat from step 2 with the longer sequence.
 *
 * Because the model is causal (it can't see the future), step 2
 * only needs to be run on the LAST token of the current sequence.
 * The other tokens have already been "seen" in previous steps.
 * (For this tiny implementation we re-run the full forward each
 * step; it's simpler and still fast enough.)
 *
 * SAMPLING STRATEGIES:
 *   - Greedy: always pick the character with the highest logit.
 *   - Top-K:  only consider the K best characters, then pick one
 *             randomly with probability proportional to the softmax.
 */
final class ModelPredictor implements PredictorPort
{
    private RandomGenerator $rng;

    public function __construct(?RandomGenerator $rng = null)
    {
        $this->rng = $rng ?? new RandomGenerator(1234);
    }

    /**
     * Generate up to maxNewTokens characters after the given prompt.
     *
     * WHY STOP AT <pad> AND <unk>?
     * <pad> means "no more text" and <unk> means "unknown character".
     * Either one is a natural end-of-text signal.
     */
    public function generate(
        LanguageModel $model,
        TokenSequence $prompt,
        SamplingConfig $sampling,
    ): TokenSequence {
        $weights = $model->weights();
        if ($weights === null) {
            throw new \RuntimeException('Model has no weights; cannot predict.');
        }
        $seq = $prompt;
        for ($step = 0; $step < $sampling->maxNewTokens; $step++) {
            // Run the forward pass and grab the last row of logits
            // (the predictions for the NEXT token).
            $logits = $this->forwardLast($weights->data, $model->config, $seq);
            $lastRow = $logits->row($logits->rows - 1);
            // Pick the next token using the chosen sampling strategy.
            $next = $this->sample($lastRow, $sampling);
            if ($next->value === 0) { // <pad>
                break;
            }
            if ($next->value === 2) { // <unk>
                break;
            }
            // Append the chosen token to our growing sequence.
            $seq = $seq->append($next);
        }

        // Return only the part AFTER the original prompt.
        return $seq->window($prompt->length(), $seq->length());
    }

    /**
     * Run the full forward pass on the current sequence and return
     * the logits for every position. (We only use the last row.)
     *
     * @param array<string, mixed> $weights
     */
    private function forwardLast(array $weights, \App\LanguageModel\Domain\Model\ModelConfig $config, TokenSequence $seq): Tensor
    {
        $D = $config->dModel;
        $V = $config->vocabSize;
        $L = $config->numLayers;
        $T = $seq->length();
        if ($T === 0) {
            throw new \InvalidArgumentException('Cannot forward an empty sequence.');
        }
        // The model can only handle sequences up to maxSeqLen long.
        // If we got a longer one, keep only the last maxSeqLen tokens.
        if ($T > $config->maxSeqLen) {
            $T = $config->maxSeqLen;
        }
        // Take the last T tokens (newest ones).
        $xIds = \array_slice($seq->toIntArray(), -$T);
        // Token + position embeddings, just like in the trainer.
        $tok = $this->lookup($weights['tokenEmbed'], $xIds);
        $pos = $this->lookup($weights['posEmbed'], \range(0, $T - 1));
        $h = $tok->add($pos);
        // Run the same stack of layers as in the trainer.
        for ($layer = 0; $layer < $L; $layer++) {
            $ln1 = new LayerNorm($D);
            $gamma1 = new Tensor($D, 1, $weights['lnAttnGamma'][$layer]);
            $beta1 = new Tensor($D, 1, $weights['lnAttnBeta'][$layer]);
            $norm1 = $ln1->forward($h, $gamma1, $beta1);
            $attn = new AttentionLayer($D);
            $attnParams = [
                'wq' => Tensor::fromMatrix($weights['attn'][$layer]['wq']),
                'wk' => Tensor::fromMatrix($weights['attn'][$layer]['wk']),
                'wv' => Tensor::fromMatrix($weights['attn'][$layer]['wv']),
                'wo' => Tensor::fromMatrix($weights['attn'][$layer]['wo']),
            ];
            $attnOut = $attn->forward($norm1, $attnParams);
            $h = $h->add($attnOut);

            $ln2 = new LayerNorm($D);
            $gamma2 = new Tensor($D, 1, $weights['lnFfnGamma'][$layer]);
            $beta2 = new Tensor($D, 1, $weights['lnFfnBeta'][$layer]);
            $norm2 = $ln2->forward($h, $gamma2, $beta2);
            $ffn = new FeedForwardLayer($D, $config->dFf);
            $ffn->setLastWeights(
                Tensor::fromMatrix($weights['ffn'][$layer]['w1']),
                Tensor::fromMatrix($weights['ffn'][$layer]['w2']),
            );
            $ffnParams = [
                'w1' => Tensor::fromMatrix($weights['ffn'][$layer]['w1']),
                'b1' => new Tensor($config->dFf, 1, $weights['ffn'][$layer]['b1']),
                'w2' => Tensor::fromMatrix($weights['ffn'][$layer]['w2']),
                'b2' => new Tensor($D, 1, $weights['ffn'][$layer]['b2']),
            ];
            $ffnOut = $ffn->forward($norm2, $ffnParams);
            $h = $h->add($ffnOut);
        }
        $finalTensor = $this->toTensor($weights['final']);
        $logits = $h->matmul($finalTensor->transpose());
        unset($V);

        return $logits;
    }

    /**
     * Pick the next token id from a list of logits.
     *
     * @param list<float> $logits one logit per possible next character
     */
    private function sample(array $logits, SamplingConfig $sampling): TokenId
    {
        if ($sampling->strategy === SamplingStrategy::Greedy) {
            // Greedy: just pick the index with the biggest logit.
            // Like always choosing the most-likely next word.
            $bestIdx = 0;
            $bestVal = -INF;
            foreach ($logits as $i => $v) {
                if ($v > $bestVal) {
                    $bestVal = $v;
                    $bestIdx = $i;
                }
            }

            return new TokenId($bestIdx);
        }
        // Top-K: keep only the K biggest logits, then pick one
        // randomly, weighted by their softmax probabilities.
        $k = $sampling->topK ?? 1;
        $indexed = [];
        foreach ($logits as $i => $v) {
            $indexed[] = ['i' => $i, 'v' => $v];
        }
        // Sort from biggest to smallest logit.
        usort($indexed, static fn ($a, $b) => $b['v'] <=> $a['v']);
        // Take just the top K.
        $topK = \array_slice($indexed, 0, $k);
        $topIds = array_map(static fn ($x) => $x['i'], $topK);
        $topLogits = array_map(static fn ($x) => $x['v'], $topK);
        // Sample one index from these K, with probability ~ exp(logit).
        $picked = $this->rng->sampleCategorical($topLogits);

        return new TokenId($topIds[$picked]);
    }

    /**
     * Look up rows from a 2D weight table by id. Same helper as in
     * the trainer.
     *
     * @param array<int, array<int, float>> $matrix
     * @param list<int> $ids
     */
    private function lookup(array $matrix, array $ids): Tensor
    {
        $D = \count($matrix[0]);
        $T = \count($ids);
        $data = array_fill(0, $T * $D, 0.0);
        for ($t = 0; $t < $T; $t++) {
            for ($d = 0; $d < $D; $d++) {
                $data[$t * $D + $d] = $matrix[$ids[$t]][$d];
            }
        }

        return new Tensor($T, $D, $data);
    }

    private function toTensor(mixed $matrix): Tensor
    {
        if ($matrix instanceof Tensor) {
            return $matrix;
        }

        return Tensor::fromMatrix($matrix);
    }
}
