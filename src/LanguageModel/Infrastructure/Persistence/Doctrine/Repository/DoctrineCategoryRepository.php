<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Repository;

use App\LanguageModel\Domain\Category\Category;
use App\LanguageModel\Domain\Category\CategoryId;
use App\LanguageModel\Domain\Repository\CategoryRepository;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\CategoryEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCategoryRepository implements CategoryRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Category $category): void
    {
        $entity = $this->em->getRepository(CategoryEntity::class)
            ->findOneBy(['uuid' => $category->id->value]);
        if ($entity === null) {
            $entity = new CategoryEntity();
            $entity->uuid = $category->id->value;
        }
        $entity->name = $category->name;
        $this->em->persist($entity);
        $this->em->flush();
    }

    public function find(CategoryId $id): ?Category
    {
        $entity = $this->em->getRepository(CategoryEntity::class)
            ->findOneBy(['uuid' => $id->value]);
        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    /**
     * @return list<Category>
     */
    public function all(): array
    {
        $entities = $this->em->getRepository(CategoryEntity::class)->findBy([], ['name' => 'ASC']);

        return array_map(fn ($e) => $this->toDomain($e), $entities);
    }

    private function toDomain(CategoryEntity $entity): Category
    {
        return new Category(
            CategoryId::fromString($entity->uuid),
            $entity->name,
        );
    }
}
