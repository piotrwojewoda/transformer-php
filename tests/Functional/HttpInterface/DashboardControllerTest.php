<?php

declare(strict_types=1);

namespace App\Tests\Functional\HttpInterface;

/**
 * @group functional
 */
final class DashboardControllerTest extends \App\Tests\Functional\WebTestCase
{
    public function testIndex_returns200(): void
    {
        $this->markTestSkipped('Requires MariaDB. Run with `make test-functional` after `docker compose up -d`.');
    }
}
