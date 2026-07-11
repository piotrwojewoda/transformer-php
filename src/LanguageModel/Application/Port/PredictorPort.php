<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Port;

use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Token\TokenSequence;

// The "predictor" is whatever turns a prompt into generated
// text. The application layer only sees this interface; the
// real implementation (ModelPredictor) lives in the
// Infrastructure layer and does the actual math.
interface PredictorPort
{
    /**
     * Generate new tokens after the given prompt, following the
     // sampling settings.
     */
    public function generate(
        LanguageModel $model,
        TokenSequence $prompt,
        SamplingConfig $sampling,
    ): TokenSequence;
}
