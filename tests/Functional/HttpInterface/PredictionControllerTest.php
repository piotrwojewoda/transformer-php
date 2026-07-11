<?php

declare(strict_types=1);

namespace App\Tests\Functional\HttpInterface;

/**
 * @group functional
 */
final class PredictionControllerTest extends \App\Tests\Functional\WebTestCase
{
    public function testPollingEndpointReturnsDoneAfterWorkerRuns(): void
    {
        $this->markTestSkipped('Requires MariaDB + worker. Run with `make test-functional` after `docker compose up -d`.');
    }
}
