<?php

declare(strict_types=1);

namespace App\Tests\Functional\HttpInterface;

/**
 * @group functional
 */
final class CorpusControllerTest extends \App\Tests\Functional\WebTestCase
{
    public function testPostingTextCreatesCorpusAndDispatchesIngestCommand(): void
    {
        $this->markTestSkipped('Requires MariaDB. Run with `make test-functional` after `docker compose up -d`.');
    }
}
