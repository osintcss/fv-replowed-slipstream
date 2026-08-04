<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FlashResponseContractTest extends TestCase
{
    private static string $amfRoot;

    public static function setUpBeforeClass(): void
    {
        self::$amfRoot = dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/';

        if (! defined('AMFPHP_ROOTPATH')) {
            define('AMFPHP_ROOTPATH', self::$amfRoot);
        }

        require_once self::$amfRoot.'Functions/UserService.php';
        require_once self::$amfRoot.'Functions/WatchToEarnRewardGrantService.php';
        require_once self::$amfRoot.'Functions/LeaderboardService.php';
        require_once self::$amfRoot.'Functions/PurchaseUnwitherService.php';
    }

    public function test_documented_flash_response_contracts_are_callable_and_well_shaped(): void
    {
        $contracts = require dirname(__DIR__).'/Fixtures/flash_response_contracts.php';
        $dispatcherSource = file_get_contents(self::$amfRoot.'Services/FlashService.php');

        foreach ($contracts as $name => $contract) {
            [$service, $method] = explode('.', $name, 2);

            self::assertTrue(
                method_exists($service, $method),
                "{$name} has no callable PHP handler"
            );
            self::assertStringContainsString(
                "Functions/{$service}.php",
                $dispatcherSource,
                "{$name} exists but its service file is not registered by FlashService"
            );

            if (($contract['registered_only'] ?? false) === true) {
                continue;
            }

            $response = $service::$method(...($contract['arguments'] ?? []));
            foreach ($contract['required'] ?? [] as $path => $expectedType) {
                $value = $this->valueAtPath($response, $path, $name);
                self::assertSame(
                    $expectedType,
                    gettype($value),
                    "{$name} returned the wrong type at {$path}"
                );
            }
            foreach ($contract['values'] ?? [] as $path => $expectedValue) {
                self::assertSame(
                    $expectedValue,
                    $this->valueAtPath($response, $path, $name),
                    "{$name} returned the wrong value at {$path}"
                );
            }
        }
    }

    private function valueAtPath(array $response, string $path, string $contract): mixed
    {
        $value = $response;
        foreach (explode('.', $path) as $segment) {
            self::assertIsArray($value, "{$contract} cannot traverse {$path} at {$segment}");
            self::assertArrayHasKey($segment, $value, "{$contract} is missing {$path}");
            $value = $value[$segment];
        }

        return $value;
    }
}
