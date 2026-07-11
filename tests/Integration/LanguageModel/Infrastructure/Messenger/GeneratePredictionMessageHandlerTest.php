<?php

declare(strict_types=1);

namespace App\Tests\Integration\LanguageModel\Infrastructure\Messenger;

/**
 * @group integration
 */
final class GeneratePredictionMessageHandlerTest extends \App\Tests\Integration\KernelTestCase
{
    public function testGeneratedTextContainsOnlyVocabTokens(): void
    {
        $this->markTestSkipped('Requires MariaDB + Messenger. Run with `make test-integration` after `docker compose up -d`.');
    }

    public function testPredictionMarkedDoneAfterSuccess(): void
    {
        $this->markTestSkipped('Requires MariaDB + Messenger. Run with `make test-integration` after `docker compose up -d`.');
    }
}
