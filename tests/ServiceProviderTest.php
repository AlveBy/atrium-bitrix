<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Tests;

final class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertIsArray(config('atrium-bitrix'));
    }
}
