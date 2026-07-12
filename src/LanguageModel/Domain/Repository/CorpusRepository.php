<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Repository;

use App\LanguageModel\Domain\Category\CategoryId;
use App\LanguageModel\Domain\Corpus\Corpus;
use App\LanguageModel\Domain\Corpus\CorpusId;

// A "repository" hides the database behind a small set of methods.
// The Domain layer uses this interface; the Infrastructure layer
// has a concrete class (DoctrineCorpusRepository) that actually
// talks to MariaDB.
//
// Why have an interface? So the rest of the code can pretend that
// saving/loading is simple, and we can swap the database engine
// without touching anything else.
interface CorpusRepository
{
    /**
     * Insert or update a corpus in the database.
     */
    public function save(Corpus $corpus): void;

    /**
     * Find a corpus by id. Returns null if it doesn't exist.
     */
    public function find(CorpusId $id): ?Corpus;

    /**
     * Get every corpus in the database, newest first.
     *
     * @return list<Corpus>
     */
    public function all(): array;

    /**
     * Get every corpus belonging to the given category, newest first.
     *
     * @return list<Corpus>
     */
    public function findByCategory(CategoryId $categoryId): array;
}
