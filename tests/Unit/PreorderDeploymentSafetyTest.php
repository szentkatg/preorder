<?php

namespace Tests\Unit;

use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PreorderDeploymentSafetyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('PREORDER_DEPLOY_LIBRARY_ONLY')) {
            define('PREORDER_DEPLOY_LIBRARY_ONLY', true);
        }

        require_once dirname(__DIR__, 2).'/infrastructure/scripts/preorder-release.php';
    }

    public function test_targets_are_fixed_and_exclude_production(): void
    {
        self::assertSame('preorder_dev', preorderReleaseTarget('Nas')['database']);
        self::assertSame('szeganet_preorder_test', preorderReleaseTarget('Staging')['database']);
        $this->expectException(DomainException::class);
        preorderReleaseTarget('Production');
    }

    #[DataProvider('blockedPaths')]
    public function test_special_changes_require_separate_preparation(string $path): void
    {
        $this->expectException(DomainException::class);
        preorderReleaseCheckPaths([$path]);
    }

    public static function blockedPaths(): array
    {
        return array_map(static fn (string $path) => [$path], [
            '.env', '.env.production', 'public/.htaccess', 'public/error_log',
            'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
            'yarn.lock', 'pnpm-lock.yaml', 'vite.config.js', 'resources/js/app.js',
            'resources/css/app.css', 'database/migrations/new.php',
            'infrastructure/docker/php/Dockerfile', 'infrastructure/compose.yaml',
            'docker-compose.yml', '.github/workflows/deploy.yml', '.gitmodules',
            '.codex/config.toml', '.agents/settings.json',
        ]);
    }

    public function test_routine_code_and_prebuilt_assets_are_allowed(): void
    {
        preorderReleaseCheckPaths([
            'app/Services/PricingService.php', 'resources/views/orders.blade.php',
            'public/js/admin-table-scroll.js', 'public/build/manifest.json',
            'docs/preorder-deployment.md', 'tests/Unit/ExampleTest.php',
            'infrastructure/scripts/deploy-preorder.ps1',
        ]);
        $this->addToAssertionCount(1);
    }
}
