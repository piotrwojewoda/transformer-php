<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Repository;

use App\LanguageModel\Domain\Category\CategoryId;
use App\LanguageModel\Domain\Corpus\Corpus;
use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\CorpusEntity;
use Doctrine\ORM\EntityManagerInterface;

// The Doctrine (database-backed) implementation of CorpusRepository.
// Translates between Corpus aggregates and CorpusEntity rows.
final readonly class DoctrineCorpusRepository implements CorpusRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Corpus $corpus): void
    {
        $repo = $this->em->getRepository(CorpusEntity::class);
        // Try to find an existing row by uuid (our public id).
        $entity = $repo->findOneBy(['uuid' => $corpus->id->value]);
        if ($entity === null) {
            // No row yet: create a new entity, copying the
            // public id and creation time.
            $entity = new CorpusEntity();
            $entity->uuid = $corpus->id->value;
            $entity->createdAt = $corpus->createdAt;
        }
        // Copy over the fields that might have changed.
        $entity->name = $corpus->name;
        $entity->rawText = $corpus->rawText;
        $entity->categoryUuid = $corpus->categoryId?->value;
        // persist() queues the change, flush() writes it to the DB.
        $this->em->persist($entity);
        $this->em->flush();
    }

    public function find(CorpusId $id): ?Corpus
    {
        $entity = $this->em->getRepository(CorpusEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            return null;
        }

        // Turn the entity back into a domain Corpus.
        return new Corpus(
            CorpusId::fromString($entity->uuid),
            $entity->name,
            $entity->rawText,
            $entity->createdAt,
            $entity->categoryUuid !== null ? CategoryId::fromString($entity->categoryUuid) : null,
        );
    }

    /**
     * @return list<Corpus>
     */
    public function all(): array
    {
        // findBy with empty criteria = all rows. Sort by id
        // descending so the newest one comes first.
        $entities = $this->em->getRepository(CorpusEntity::class)->findBy([], ['id' => 'DESC']);
        $out = [];
        foreach ($entities as $entity) {
            $out[] = new Corpus(
                CorpusId::fromString($entity->uuid),
                $entity->name,
                $entity->rawText,
                $entity->createdAt,
                $entity->categoryUuid !== null ? CategoryId::fromString($entity->categoryUuid) : null,
            );
        }

        return $out;
    }

    /**
     * @return list<Corpus>
     */
    public function findByCategory(CategoryId $categoryId): array
    {
        $entities = $this->em->getRepository(CorpusEntity::class)
            ->findBy(['categoryUuid' => $categoryId->value], ['id' => 'DESC']);
        $out = [];
        foreach ($entities as $entity) {
            $out[] = new Corpus(
                CorpusId::fromString($entity->uuid),
                $entity->name,
                $entity->rawText,
                $entity->createdAt,
                $entity->categoryUuid !== null ? CategoryId::fromString($entity->categoryUuid) : null,
            );
        }

        return $out;
    }
}
