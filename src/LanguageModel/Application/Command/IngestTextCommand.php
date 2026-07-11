<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Command;

use App\LanguageModel\Domain\Corpus\CorpusId;

// "Please add this text to a new corpus". The matching handler
// creates the Corpus aggregate and a Vocabulary for it.
final readonly class IngestTextCommand
{
    public function __construct(
        public string $name,
        public string $text,
    ) {
        if (mb_strlen($name, 'UTF-8') === 0) {
            throw new \InvalidArgumentException('Corpus name cannot be empty.');
        }
        if (mb_strlen($name, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Corpus name cannot exceed 120 characters.');
        }
        if (mb_strlen($text, 'UTF-8') === 0) {
            throw new \InvalidArgumentException('Text cannot be empty.');
        }
    }
}
