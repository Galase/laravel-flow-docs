<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Tests;

use Galase\FlowDocs\FlowDocsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FlowDocsServiceProvider::class,
        ];
    }
}
