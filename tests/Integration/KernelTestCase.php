<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Component\HttpKernel\KernelInterface;

class KernelTestCase extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected static function getKernel(): KernelInterface
    {
        return self::$kernel;
    }
}
