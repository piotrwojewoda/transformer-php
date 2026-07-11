<?php

declare(strict_types=1);

namespace App\Tests\Integration\LanguageModel\EndToEnd;

/**
 * End-to-end: ingest text, create model, train N epochs, predict.
 *
 * @group integration
 */
final class TrainAndPredictFlowTest extends \App\Tests\Integration\KernelTestCase
{
    public function testHappyPath(): void
    {
        $this->markTestSkipped('Requires MariaDB + Messenger + workers. Run with `make test-integration` after `docker compose up -d`.');
    }
}
