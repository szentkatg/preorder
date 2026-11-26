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

    public function test_audit_preparation_is_explicit_and_content_pinned(): void
    {
        $path = preorderAuditMigrationPath();
        preorderAuditMigrationValidate(file_get_contents(dirname(__DIR__, 2).'/'.$path));
        preorderReleaseCheckPaths([$path], true);
        $this->expectException(DomainException::class);
        preorderReleaseCheckPaths([$path]);
    }

    public function test_audit_preparation_cannot_allow_other_migrations(): void
    {
        $this->expectException(DomainException::class);
        preorderReleaseCheckPaths(['database/migrations/unreviewed.php'], true);
    }

    public function test_audit_preparation_rejects_modified_migration_contents(): void
    {
        $this->expectException(DomainException::class);
        preorderAuditMigrationValidate(file_get_contents(dirname(__DIR__, 2).'/'.preorderAuditMigrationPath())."\n");
    }

    public function test_only_exact_new_permission_and_migration_rows_are_allowed(): void
    {
        preorderAuditAddedRows('permissions', [['id' => 1], ['id' => 2, 'name' => 'View:OrderStatusHistory', 'guard_name' => 'web']], ['1'], 42);
        preorderAuditAddedRows('migrations', [['id' => 1, 'migration' => basename(preorderAuditMigrationPath(), '.php'), 'batch' => '42']], [], 42);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidAdditions')]
    public function test_unexpected_additions_stop_verification(string $table, array $rows): void
    {
        $this->expectException(DomainException::class);
        preorderAuditAddedRows($table, $rows, [], 42);
    }

    public static function invalidAdditions(): array
    {
        return [
            ['permissions', []],
            ['permissions', [['id' => 1, 'name' => 'View:Other', 'guard_name' => 'web']]],
            ['permissions', [['id' => 1, 'name' => 'View:OrderStatusHistory', 'guard_name' => 'partner']]],
            ['permissions', [['id' => 1], ['id' => 2]]],
            ['migrations', [['id' => 1, 'migration' => 'unreviewed', 'batch' => 42]]],
            ['migrations', [['id' => 1, 'migration' => '2026_11_26_090000_create_order_status_logs_table', 'batch' => 43]]],
        ];
    }
}
