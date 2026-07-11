<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Model;

// "enum" is short for "enumeration": a fixed list of allowed values.
// A model can only ever be in one of these states, and the order
// below is also the lifecycle order. The "string" part means each
// case has a human-readable name (used when saving to the database
// or showing in the UI).
enum ModelStatus: string
{
    // The model was just created. It has metadata but no weights yet.
    case Draft = 'draft';
    // Weights have been initialized (randomly). The model is ready
    // to be trained.
    case Ready = 'ready';
    // A training job is currently running.
    case Training = 'training';
    // Training has finished successfully.
    case Trained = 'trained';
    // Something went wrong (an exception, a missing corpus, etc).
    case Failed = 'failed';
}
