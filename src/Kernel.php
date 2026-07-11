<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

// The Kernel is the entry point of the Symfony application:
// it sets up the service container, reads configuration, and
// handles requests.
//
// "MicroKernelTrait" is a small helper that lets us keep all
// the configuration in the config/ directory without writing
// a long boot() method. The actual configuration files
// (services.yaml, routes.yaml, etc.) tell the kernel what
// bundles to load and how to wire everything together.
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
