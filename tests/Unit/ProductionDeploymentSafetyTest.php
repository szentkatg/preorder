<?php

namespace Tests\Unit;

use DomainException;
use PHPUnit\Framework\TestCase;

class ProductionDeploymentSafetyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('PREORDER_DEPLOY_LIBRARY_ONLY')) {
            define('PREORDER_DEPLOY_LIBRARY_ONLY', true);
        }
        if (! defined('PREORDER_PRODUCTION_LIBRARY_ONLY')) {
            define('PREORDER_PRODUCTION_LIBRARY_ONLY', true);
        }
        require_once dirname(__DIR__, 2).'/infrastructure/scripts/preorder-release.php';
        require_once dirname(__DIR__, 2).'/infrastructure/scripts/production-release.php';
    }

    public function test_only_the_three_reviewed_additive_migrations_are_allowed(): void
    {
        self::assertCount(3, productionReleaseMigrations());
        productionReleasePaths(array_map(fn ($name) => 'database/migrations/'.$name.'.php', productionReleaseMigrations()));
        $this->expectException(DomainException::class);
        productionReleasePaths(['database/migrations/2026_11_26_drop_orders.php']);
    }

    public function test_runtime_configuration_cannot_be_overwritten(): void
    {
        $this->expectException(DomainException::class);
        productionReleasePaths(['public/.htaccess']);
    }

    public function test_dependencies_require_separate_review(): void
    {
        $this->expectException(DomainException::class);
        productionReleasePaths(['composer.lock']);
    }

    public function test_nas_staging_entrypoint_still_rejects_production(): void
    {
        $this->expectException(DomainException::class);
        preorderReleaseTarget('Production');
    }
}
