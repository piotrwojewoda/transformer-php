<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Query;

use App\LanguageModel\Domain\Model\ModelId;

// A "query" in CQRS is the read-side counterpart to a command.
// It just asks "please give me some information", without
// changing anything.
//
// This one says: "please give me the data for this model".
final readonly class GetModelQuery
{
    public function __construct(public ModelId $modelId)
    {
    }
}
