<?php

declare(strict_types=1);

namespace App\Tests\Integration\LanguageModel\Infrastructure\Persistence\Doctrine;

/**
 * @group integration
 */
final class DoctrineVocabularyRepositoryTest extends \App\Tests\Integration\KernelTestCase
{
    public function testSaveAndLoadVocabulary(): void
    {
        $this->markTestSkipped('Requires MariaDB. Run with `make test-integration` after `docker compose up -d`.');
    }
}
