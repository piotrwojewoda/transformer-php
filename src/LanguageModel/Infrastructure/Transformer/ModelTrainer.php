<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\Weights;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\Domain\Training\TrainingLoss;

/**
 * Top-level orchestrator: builds the model from a Weights value object,
 * runs forward + backward + Adam step.
 *
 * WHAT IS THIS CLASS?
 * -------------------
 * This is the "conductor" of the model. It doesn't do math itself
 * (the layers do), but it knows the order of all the steps and
 * wires them together. It implements the TrainerPort, which the
 * application layer uses to train the model.
 *
 * THE FULL FORWARD PASS (pre-norm Transformer, one block per layer):
 *   x = tokEmb[t] + posEmb[t]            (T, dModel)
 *   for each layer:
 *     x = x + Attention(LayerNorm(x))
 *     x = x + FFN(LayerNorm(x))
 *   logits = x @ final                   (T, vocabSize)
 *   loss   = softmax_cross_entropy(logits, target)
 *
 * "PRE-NORM" MEANS:
 * We normalize the input BEFORE attention/FFN (instead of after).
 * This is known to make training more stable in deep Transformers.
 *
 * THE RESIDUAL "x = x + ..." TRICK:
 * We add the layer's output back to the input. This creates a
 * "shortcut" that helps gradients flow backward without vanishing,
 * which makes it possible to train many layers in a row.
 */
final class ModelTrainer implements \App\LanguageModel\Application\Port\TrainerPort
{
    public function __construct(private readonly ?RandomGenerator $rng = null)
    {
    }

    /**
     * Create a brand-new set of random weights for a model.
     *
     * The weights are organized in a nested array:
     *   - tokenEmbed: a table (vocabSize x dModel), one row per token.
     *   - posEmbed: a table (maxSeqLen x dModel), one row per position.
     *   - attn: a list of layers, each with Wq, Wk, Wv, Wo matrices.
     *   - ffn: a list of layers, each with W1, b1, W2, b2.
     *   - lnAttnGamma, lnAttnBeta, lnFfnGamma, lnFfnBeta:
     *       the gamma and beta vectors for the LayerNorms (initially
     *       gamma=1, beta=0 so LayerNorm is a no-op at the start).
     *   - final: a (vocabSize x dModel) matrix that turns the last
     *       hidden vector into one logit per possible next character.
     */
    public function initializeWeights(ModelConfig $config): Weights
    {
        // Start the random generator with a fixed seed if none was
        // given, so the initial weights are reproducible.
        $tokenEmbed = $this->initMatrix($config->vocabSize, $config->dModel);
        $posEmbed = $this->initMatrix($config->maxSeqLen, $config->dModel);

        $attn = [];
        $ffn = [];
        $lnAttnGamma = [];
        $lnAttnBeta = [];
        $lnFfnGamma = [];
        $lnFfnBeta = [];
        for ($layer = 0; $layer < $config->numLayers; $layer++) {
            // Use the small math layers just to get their random init.
            $attnLayer = (new AttentionLayer($config->dModel))->initParams($this->rng);
            $ffnLayer = (new FeedForwardLayer($config->dModel, $config->dFf))->initParams($this->rng);
            $attn[$layer] = [];
            foreach (['wq', 'wk', 'wv', 'wo'] as $m) {
                $attn[$layer][$m] = $attnLayer[$m]->toMatrix();
            }
            $ffn[$layer] = [];
            foreach (['w1', 'w2'] as $m) {
                $ffn[$layer][$m] = $ffnLayer[$m]->toMatrix();
            }
            $ffn[$layer]['b1'] = $ffnLayer['b1']->data();
            $ffn[$layer]['b2'] = $ffnLayer['b2']->data();
            // LayerNorm: start with gamma=1 and beta=0 so it doesn't
            // change the input at the very beginning of training.
            $lnAttnGamma[$layer] = array_fill(0, $config->dModel, 1.0);
            $lnAttnBeta[$layer] = array_fill(0, $config->dModel, 0.0);
            $lnFfnGamma[$layer] = array_fill(0, $config->dModel, 1.0);
            $lnFfnBeta[$layer] = array_fill(0, $config->dModel, 0.0);
        }
        $final = $this->initMatrix($config->vocabSize, $config->dModel);

        return new Weights([
            'tokenEmbed' => $tokenEmbed,
            'posEmbed' => $posEmbed,
            'attn' => $attn,
            'ffn' => $ffn,
            'lnAttnGamma' => $lnAttnGamma,
            'lnAttnBeta' => $lnAttnBeta,
            'lnFfnGamma' => $lnFfnGamma,
            'lnFfnBeta' => $lnFfnBeta,
            'final' => $final,
        ]);
    }

    /**
     * Helper: build a small random matrix using Box-Muller (normal
     * distribution) with std = 1 / sqrt(cols). This is the same
     * trick the EmbeddingLayer uses to keep starting values sane.
     */
    private function initMatrix(int $rows, int $cols): array
    {
        $rng = $this->rng ??= new RandomGenerator(42);
        $std = 1.0 / \sqrt($cols);
        $data = [];
        for ($i = 0; $i < $rows; $i++) {
            $row = [];
            for ($j = 0; $j < $cols; $j++) {
                $u1 = max(1e-12, $rng->nextFloat());
                $u2 = $rng->nextFloat();
                $z = \sqrt(-2.0 * \log($u1)) * \cos(2.0 * M_PI * $u2);
                $row[] = $z * $std;
            }
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Train the model for one epoch (one small sample, then update).
     *
     * An "epoch" here means: pick a random window of seqLen tokens
     * from the corpus, do one forward+backward pass on that window,
     * and apply one Adam step.
     */
    public function trainOneEpoch(
        LanguageModel $model,
        TokenSequence $data,
        TrainingConfig $config,
    ): Weights {
        $weights = $model->weights();
        if ($weights === null) {
            throw new \RuntimeException('Model has no weights to train.');
        }
        // T is the length of our training window. It can't be longer
        // than the data minus one (because we need a "next" token).
        $T = min($config->seqLen, $data->length() - 1);
        if ($T < 1) {
            throw new \InvalidArgumentException('Training data too short for one sample.');
        }
        // Pick a random starting position so we don't always train on
        // the same slice of the corpus.
        $start = 0;
        $rng = $this->rng ??= new RandomGenerator(42);
        if ($data->length() > $config->seqLen + 1) {
            $start = $rng->nextInt(0, $data->length() - $T - 1);
        }
        // xIds: the input tokens (positions 0..T-1).
        // yIds: the targets (positions 1..T, i.e. shifted by one).
        // The model is asked to predict the next character, so yIds
        // is xIds shifted by one position.
        $xIds = \array_slice($data->toIntArray(), $start, $T);
        $yIds = \array_slice($data->toIntArray(), $start + 1, $T);

        // Make a fresh Adam (without bias correction) for this step.
        $adam = new Adam($config->learningRate);
        // Compute the gradients for every weight.
        $grads = $this->computeGradients($weights->data, $model->config, $xIds, $yIds);
        // Apply the Adam step to get new weights.
        $newWeights = $adam->step($weights->data, $grads);

        return new Weights($newWeights);
    }

    /**
     * Run forward + backward, returning a flat dictionary of
     * gradients (one per weight matrix/vector).
     *
     * @param array<string, mixed> $weights
     * @param list<int> $xIds
     * @param list<int> $yIds
     * @return array<string, array<int, array<int, float>>>
     */
    public function computeGradients(array $weights, ModelConfig $config, array $xIds, array $yIds): array
    {
        $T = \count($xIds);
        $D = $config->dModel;
        $V = $config->vocabSize;
        $L = $config->numLayers;

        // === FORWARD PASS ===

        // Token embedding forward: (T, D) from rows of W_emb at xIds[t].
        // Then add the positional embedding so the model knows which
        // word is first, second, etc.
        $tok = $this->lookup($weights['tokenEmbed'], $xIds);
        $pos = $this->lookup($weights['posEmbed'], \range(0, $T - 1));
        $h = $tok->add($pos);

        // Shorter names for the weight pieces.
        $attn = $weights['attn'];
        $ffn = $weights['ffn'];
        $lnAttnGamma = $weights['lnAttnGamma'];
        $lnAttnBeta = $weights['lnAttnBeta'];
        $lnFfnGamma = $weights['lnFfnGamma'];
        $lnFfnBeta = $weights['lnFfnBeta'];

        // We need to remember every layer so we can call backward()
        // on it later.
        $attnGrads = [];
        $ffnGrads = [];
        $lnAttnGammaGrads = [];
        $lnAttnBetaGrads = [];
        $lnFfnGammaGrads = [];
        $lnFfnBetaGrads = [];

        for ($layer = 0; $layer < $L; $layer++) {
            // Block 1: pre-norm attention.
            //   norm1 = LayerNorm(h)
            //   attnOut = Attention(norm1)
            //   h = h + attnOut   (residual)
            $ln1 = new LayerNorm($D);
            $gamma1 = new Tensor($D, 1, $lnAttnGamma[$layer]);
            $beta1 = new Tensor($D, 1, $lnAttnBeta[$layer]);
            $norm1 = $ln1->forward($h, $gamma1, $beta1);

            $attnLayer = new AttentionLayer($D);
            $attnParams = [
                'wq' => Tensor::fromMatrix($attn[$layer]['wq']),
                'wk' => Tensor::fromMatrix($attn[$layer]['wk']),
                'wv' => Tensor::fromMatrix($attn[$layer]['wv']),
                'wo' => Tensor::fromMatrix($attn[$layer]['wo']),
            ];
            $attnOut = $attnLayer->forward($norm1, $attnParams);
            $h = $h->add($attnOut);

            // Block 2: pre-norm feed-forward.
            //   norm2 = LayerNorm(h)
            //   ffnOut = FFN(norm2)
            //   h = h + ffnOut   (residual)
            $ln2 = new LayerNorm($D);
            $gamma2 = new Tensor($D, 1, $lnFfnGamma[$layer]);
            $beta2 = new Tensor($D, 1, $lnFfnBeta[$layer]);
            $norm2 = $ln2->forward($h, $gamma2, $beta2);

            $ffnLayer = new FeedForwardLayer($D, $config->dFf);
            // Tell the FFN what W1 and W2 are so it can compute
            // gradients for them in backward().
            $ffnLayer->setLastWeights(
                Tensor::fromMatrix($ffn[$layer]['w1']),
                Tensor::fromMatrix($ffn[$layer]['w2']),
            );
            $ffnParams = [
                'w1' => Tensor::fromMatrix($ffn[$layer]['w1']),
                'b1' => new Tensor($config->dFf, 1, $ffn[$layer]['b1']),
                'w2' => Tensor::fromMatrix($ffn[$layer]['w2']),
                'b2' => new Tensor($D, 1, $ffn[$layer]['b2']),
            ];
            $ffnOut = $ffnLayer->forward($norm2, $ffnParams);
            $h = $h->add($ffnOut);

            // Save the layer objects so we can call backward() on them.
            $attnGrads[$layer] = $attnLayer;
            $ffnGrads[$layer] = $ffnLayer;
            $lnAttnGammaGrads[$layer] = $ln1;
            $lnAttnBetaGrads[$layer] = $ln1;
            $lnFfnGammaGrads[$layer] = $ln2;
            $lnFfnBetaGrads[$layer] = $ln2;
        }

        // === OUTPUT PROJECTION + LOSS ===

        // logits = h @ final   (T, V)
        // We store "final" as (V, D) so logits = h @ final.T.
        $finalTensor = $this->toTensor($weights['final']);
        $logits = $h->matmul($finalTensor->transpose());

        // Compute the loss and the gradient of the loss w.r.t. logits.
        $lossFn = new SoftmaxCrossEntropy();
        $loss = $lossFn->forward($logits, $yIds);
        $dLogits = $lossFn->backward($yIds);

        // dH = dLogits @ final  (how the hidden state should change)
        $dH = $dLogits->matmul($finalTensor);
        // dFinal = h.T @ dLogits  (how the final projection should change)
        $dFinal = $h->transpose()->matmul($dLogits);

        // Set up empty buckets for all the gradients we'll fill in below.
        $grads = [
            'tokenEmbed' => $this->zeroMatrix($V, $D),
            'posEmbed' => $this->zeroMatrix($config->maxSeqLen, $D),
            'attn' => [],
            'ffn' => [],
            'lnAttnGamma' => [],
            'lnAttnBeta' => [],
            'lnFfnGamma' => [],
            'lnFfnBeta' => [],
            'final' => $dFinal->toMatrix(),
        ];

        // === BACKWARD PASS ===
        // We walk through the layers in REVERSE order, undoing each
        // forward step in reverse.
        for ($layer = $L - 1; $layer >= 0; $layer--) {
            // Backward through the FFN residual block.
            //   Forward did: h = h + ffn(norm2)
            //   So dH can be split into two paths:
            //     1. The direct "h +" path (dH itself).
            //     2. The through-FFN path: ffn.backward(dH).
            //   The new dH is the sum of these two.
            $dFfnOut = $dH;
            $dNorm2 = $ffnGrads[$layer]->backward($dFfnOut);
            $dH = $dH->add($dNorm2);
            $grads['lnFfnGamma'][$layer] = $lnFfnGammaGrads[$layer]->getGammaGrad()->toMatrix()[0];
            $grads['lnFfnBeta'][$layer] = $lnFfnGammaGrads[$layer]->getBetaGrad()->toMatrix()[0];
            $grads['ffn'][$layer] = [
                'w1' => $ffnGrads[$layer]->getW1Grad()->toMatrix(),
                'b1' => $ffnGrads[$layer]->getB1Grad()->data(),
                'w2' => $ffnGrads[$layer]->getW2Grad()->toMatrix(),
                'b2' => $ffnGrads[$layer]->getB2Grad()->data(),
            ];

            // Backward through the attention residual block, same pattern.
            $dAttnOut = $dH;
            $dNorm1 = $attnGrads[$layer]->backward($dAttnOut);
            $dH = $dH->add($dNorm1);
            $grads['lnAttnGamma'][$layer] = $lnAttnGammaGrads[$layer]->getGammaGrad()->toMatrix()[0];
            $grads['lnAttnBeta'][$layer] = $lnAttnGammaGrads[$layer]->getBetaGrad()->toMatrix()[0];
            $grads['attn'][$layer] = [
                'wq' => $attnGrads[$layer]->getWqGrad()->toMatrix(),
                'wk' => $attnGrads[$layer]->getWkGrad()->toMatrix(),
                'wv' => $attnGrads[$layer]->getWvGrad()->toMatrix(),
                'wo' => $attnGrads[$layer]->getWoGrad()->toMatrix(),
            ];
        }

        // Scatter the dH into the token and positional embedding
        // tables. Same idea as the EmbeddingLayer's backward pass.
        $grads['tokenEmbed'] = $this->scatter($this->zeroMatrix($V, $D), $xIds, $dH);
        $grads['posEmbed'] = $this->scatter($this->zeroMatrix($config->maxSeqLen, $D), \range(0, $T - 1), $dH);

        // Remember the loss so the caller can read it after training.
        $this->lastLoss = new TrainingLoss($loss);

        return $grads;
    }

    // The loss from the most recent trainOneEpoch call. The handler
    // reads this to record the training history.
    public ?TrainingLoss $lastLoss = null;

    /**
     * Helper: turn a 2D PHP array (or a Tensor) into a Tensor.
     * Used because the weights can be stored either way.
     */
    private function toTensor(mixed $matrix): Tensor
    {
        if ($matrix instanceof Tensor) {
            return $matrix;
        }

        return Tensor::fromMatrix($matrix);
    }

    /**
     * Look up the rows of a 2D table by a list of ids.
     * Like reading specific lines from a phone book.
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

    /**
     * Add the rows of a Tensor into a target table at the given ids.
     * Used to accumulate gradients into embedding tables.
     *
     * @param array<int, array<int, float>> $target
     * @param list<int> $ids
     * @param Tensor $grad
     * @return array<int, array<int, float>>
     */
    private function scatter(array $target, array $ids, Tensor $grad): array
    {
        $T = \count($ids);
        $D = $grad->cols;
        for ($t = 0; $t < $T; $t++) {
            for ($d = 0; $d < $D; $d++) {
                $target[$ids[$t]][$d] += $grad->data()[$t * $D + $d];
            }
        }

        return $target;
    }

    /**
     * Build a 2D array full of zeros. Used to make empty gradient
     * tables that we then fill in.
     *
     * @return array<int, array<int, float>>
     */
    private function zeroMatrix(int $rows, int $cols): array
    {
        $out = [];
        for ($i = 0; $i < $rows; $i++) {
            $out[$i] = array_fill(0, $cols, 0.0);
        }

        return $out;
    }
}
