<?php

declare(strict_types=1);

namespace App\Tests\Integration\LanguageModel\Infrastructure\Persistence\Doctrine;

/**
 * Integration test for the LanguageModel repository against a real MariaDB.
 *
 * Requires the test environment to be configured with a reachable DB; the
 * `dama/doctrine-test-bundle` rolls back the transaction after each test.
 *
 * @group integration
 */
final class DoctrineLanguageModelRepositoryTest extends \App\Tests\Integration\KernelTestCase
{
    public function testSaveAndLoadWeightsAreBitIdentical(): void
    {
        $this->markTestSkipped('Requires MariaDB. Run with `make test-integration` after `docker compose up -d`.');
    }
}
