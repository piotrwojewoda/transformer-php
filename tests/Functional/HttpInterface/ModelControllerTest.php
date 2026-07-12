<?php

declare(strict_types=1);

namespace App\Tests\Functional\HttpInterface;

/**
 * @group functional
 */
final class ModelControllerTest extends \App\Tests\Functional\WebTestCase
{
    public function testCreatingModelAllocatesWeightRows(): void
    {
        $this->markTestSkipped('Requires MariaDB. Run with `make test-functional` after `docker compose up -d`.');
    }

    public function testTrainingProgressEndpointReturnsPartialHtml(): void
    {
        $this->markTestSkipped('Requires MariaDB. Run with `make test-functional` after `docker compose up -d`.');
    }

    public function testDetailPageContainsPollingScriptWhenTraining(): void
    {
        $this->markTestSkipped('Requires MariaDB. Run with `make test-functional` after `docker compose up -d`.');
    }
}
