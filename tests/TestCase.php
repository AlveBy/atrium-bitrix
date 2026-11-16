<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Tests;

use Atrium\Bitrix\BitrixServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BitrixServiceProvider::class];
    }
}
