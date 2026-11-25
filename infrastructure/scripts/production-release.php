<?php

use App\Models\PartnerUser;
use App\Models\Product;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Combined with the pure backup helpers by deploy-production.ps1, never routed through the NAS/staging target map.
function productionReleaseMigrations(): array
{
    return [
        '2026_11_23_130000_add_partner_visibility_to_products_table',
        '2026_11_23_150000_add_exclusivity_to_products_table',
        '2026_11_23_160000_add_group_code_pattern_to_pricing_parameters',
    ];
}

function productionReleasePaths(array $paths): void
{
    $approved = array_map(fn ($name) => 'database/migrations/'.$name.'.php', productionReleaseMigrations());
    preorderReleaseCheckPaths(array_values(array_diff($paths, $approved)));
}

if (defined('PREORDER_PRODUCTION_LIBRARY_ONLY')) {
    return;
}

$downStartedHere = false;
try {
    $mode = '__MODE__';
    $expected = '__COMMIT__';
    $runId = '__RUN_ID__';
    syncGuard(in_array($mode, ['inspect', 'backup', 'deploy', 'verify', 'smoke'], true), 'Invalid production phase');
    syncGuard((bool) preg_match('/^[a-f0-9]{40}$/D', $expected), 'Invalid production commit');
    syncGuard((bool) preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D', $runId), 'Invalid production run identifier');
    $root = '/home/szeganet/preorder-production-git';
    $stage = '/home/szeganet/preorder-staging-releases/20261029-561a247';
    $branch = 'release/production-20261122';
    $php = '/opt/alt/php83/usr/bin/php';
    syncGuard(realpath($root) === $root && ! is_link($root), 'Unexpected production root');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $cfg = config('database.connections.mysql');
    $env = Dotenv::parse(file_get_contents($root.'/.env'));
    syncGuard(base_path() === $root && $app->environment('production') && ! config('app.debug')
        && config('database.default') === 'mysql' && $cfg['database'] === 'szeganet_elorendeles'
        && ($env['APP_ENV'] ?? '') === 'production' && ($env['DB_DATABASE'] ?? '') === 'szeganet_elorendeles'
        && empty($env['DB_URL']) && config('app.url') === 'https://rendeles.szeganet.hu', 'Production isolation guard failed');
    $db = DB::connection()->getPdo();
    syncGuard($db->query('SELECT DATABASE()')->fetchColumn() === 'szeganet_elorendeles', 'Wrong active database');
    syncGuard(syncRead(['git', 'branch', '--show-current'], $root) === $branch, 'Unexpected production branch');
    syncGuard((bool) preg_match('~^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)szentkatg/preorder(?:\.git)?/?$~', syncRead(['git', 'remote', 'get-url', 'origin'], $root)), 'Unexpected production repository');
    syncGuard(syncRead(['git', 'rev-parse', 'HEAD'], $stage) === $expected, 'Target is not the installed staging commit');
    $head = syncRead(['git', 'rev-parse', 'HEAD'], $root);
    $dirty = array_filter(array_map('trim', explode("\n", syncRead(['git', 'status', '--porcelain', '--untracked-files=no'], $root))));
    syncGuard(array_diff($dirty, ['D public/error_log']) === [], 'Production has unreviewed tracked changes');
    // Inspect using the already deployed staging repository, without fetching into production.
    syncRead(['git', 'merge-base', '--is-ancestor', $head, $expected], $stage);
    $changes = array_filter(explode("\n", syncRead(['git', 'diff', '--name-only', $head, $expected], $stage)));
    productionReleasePaths($changes);
    $applied = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $auditRoot = '/home/szeganet/.preorder-production-releases';
    $audit = $auditRoot.'/production-'.$runId.'-'.substr($expected, 0, 12);
    if ($mode === 'inspect') {
        syncGuard(is_executable('/usr/bin/mariadb-dump'), 'Backup executable missing');
        echo json_encode(['status' => 'checked', 'head' => $head, 'target' => $expected, 'maintenance' => $app->isDownForMaintenance(),
            'migrations' => count($applied), 'approved_new_migrations' => array_values(array_diff(productionReleaseMigrations(), $applied)),
            'changed_files' => count($changes), 'audit' => $audit, 'php_version' => PHP_VERSION], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    $lock = fopen('/tmp/preorder-production-release.lock', 'c');
    syncGuard($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Another production phase is running');
    umask(0022);
    if ($mode === 'backup') {
        syncGuard(! $app->isDownForMaintenance() && ! file_exists($audit), 'Maintenance or audit already exists');
        syncRead(['git', 'fetch', '--no-tags', 'origin', 'refs/heads/'.$branch], $root);
        syncGuard(syncRead(['git', 'rev-parse', 'FETCH_HEAD'], $root) === $expected, 'Published target changed');
        if (! is_dir($auditRoot)) {
            syncGuard(mkdir($auditRoot, 0700), 'Cannot create audit root');
        }
        syncGuard(! is_link($auditRoot) && mkdir($audit, 0700), 'Cannot create private audit');
        syncRun(['git', 'archive', '--format=tar', '--output='.$audit.'/code-before.tar', 'HEAD'], 'archive-code', $root, $audit);
        chmod($audit.'/code-before.tar', 0600);
        syncWrite($audit.'/.env.before', file_get_contents($root.'/.env'));
        syncWrite($audit.'/public-htaccess.before', file_get_contents($root.'/public/.htaccess'));
        syncRun([$php, 'artisan', 'down', '--retry=30', '--no-interaction'], 'maintenance-down', $root, $audit);
        $downStartedHere = true;
        $fingerprints = [];
        $volatile = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'imports', 'exports', 'failed_import_rows', 'migrations'];
        foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (! in_array($table, $volatile, true)) {
                $fingerprints[$table] = syncFingerprint($db, $table);
            }
        }
        $before = ['head' => $head, 'target' => $expected, 'fingerprints' => $fingerprints, 'migrations' => $applied,
            'env_sha256' => hash_file('sha256', $root.'/.env'), 'htaccess_sha256' => hash_file('sha256', $root.'/public/.htaccess'),
            'public_mode' => fileperms($root.'/public') & 0777, 'stage_head' => $expected,
            'product_ids' => $db->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)];
        syncWrite($audit.'/before.json', json_encode($before, JSON_THROW_ON_ERROR));
        syncBackup($cfg, $root, $audit);
        echo json_encode(['status' => 'backup_ready', 'tables' => count($fingerprints), 'audit' => $audit], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    syncGuard(is_file($audit.'/backup.json') && is_file($audit.'/before.json'), 'Verified production backup required');
    $before = json_decode(file_get_contents($audit.'/before.json'), true, flags: JSON_THROW_ON_ERROR);
    syncGuard($before['target'] === $expected && ($head === $expected || $head === $before['head']), 'Backup and installed commit mismatch');
    syncGuard(hash_file('sha256', $root.'/.env') === $before['env_sha256'] && hash_file('sha256', $root.'/public/.htaccess') === $before['htaccess_sha256'], 'Runtime configuration changed');
    if ($mode === 'deploy') {
        syncGuard($app->isDownForMaintenance(), 'Production must remain in maintenance during installation');
        if ($head !== $expected) {
            syncRun(['git', 'merge', '--ff-only', $expected], 'fast-forward', $root, $audit);
        }
        $suffix = '-'.bin2hex(random_bytes(3));
        syncRun([$php, 'artisan', 'optimize:clear', '--no-interaction'], 'optimize-clear'.$suffix, $root, $audit);
        $files = array_map(fn ($f) => basename($f, '.php'), glob($root.'/database/migrations/*.php'));
        syncGuard(array_diff($files, $applied, productionReleaseMigrations()) === [], 'Unapproved pending migration');
        syncRun([$php, 'artisan', 'migrate', '--force', '--no-interaction'], 'migrate'.$suffix, $root, $audit);
        syncRun([$php, 'artisan', 'filament:optimize-clear', '--no-interaction'], 'filament-clear'.$suffix, $root, $audit);
        syncRun([$php, 'artisan', 'optimize', '--no-interaction'], 'optimize'.$suffix, $root, $audit);
        echo json_encode(['status' => 'installed', 'commit' => $expected, 'maintenance' => true], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    syncGuard($head === $expected, 'Expected code must be installed');
    if ($mode === 'smoke') {
        // This process alone bypasses maintenance. Public access stays disabled until verification succeeds.
        PreventRequestsDuringMaintenance::except(['*']);
        config(['session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'log']);
        // Some GET pages initialise personal table preferences. Roll those back
        // as well; no synthetic login or retained business-data write is needed.
        DB::beginTransaction();
        try {
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $checks = [];
            $get = function (string $path, ?object $user = null, string $guard = 'web', ?int $season = null) use ($kernel, &$checks): string {
                Auth::forgetGuards();
                if ($user) {
                    Auth::guard($guard)->setUser($user);
                }
                if ($season) {
                    session(['partner.season_id' => $season, 'partner.locale' => 'hu']);
                }
                $request = Request::create('https://rendeles.szeganet.hu'.$path, 'GET');
                $response = $kernel->handle($request);
                syncGuard($response->getStatusCode() === 200, 'HTTP render failed: '.$path.' ('.$response->getStatusCode().')');
                $body = (string) $response->getContent();
                $kernel->terminate($request, $response);
                $checks[] = ['path' => $path, 'status' => 200];

                return $body;
            };
            $get('/admin/login');
            $get('/partner/login');
            $admin = User::role('super_admin')->first();
            syncGuard($admin !== null, 'No super admin for smoke check');
            foreach (['/admin/products', '/admin/pricing-parameters', '/admin/pricing-product-overview', '/admin/pricing-huf-retail-calculations'] as $path) {
                $get($path, $admin);
            }
            foreach (['partner_admin', 'address_user', 'sales_rep'] as $role) {
                $sample = null;
                $user = null;
                foreach (PartnerUser::where('active', true)->where('role', $role)->orderBy('id')->limit(100)->get() as $candidate) {
                    $order = $candidate->accessibleOrdersQuery()->whereHas('season', fn ($q) => $q->visibleToPartners())->first();
                    if ($order) {
                        $sample = $order;
                        $user = $candidate;
                        break;
                    }
                }
                syncGuard($sample !== null, 'No accessible sample for role '.$role);
                $get('/partner/orders/select', $user, 'partner', (int) $sample->season_id);
                $get('/partner/orders/'.$sample->id.'/overview', $user, 'partner', (int) $sample->season_id);
                $group = Product::visibleToPartners()->where('season_id', $sample->season_id)->where('brand_id', $sample->brand_id)
                    ->where('order_sheet_type_id', $sample->order_sheet_type_id)->whereNotNull('catalog_group_name_hu')->value('catalog_group_name_hu');
                syncGuard($group !== null, 'No visible group for sample role '.$role);
                $html = $get('/partner/orders/'.$sample->id.'/catalog-groups/'.rawurlencode($group), $user, 'partner', (int) $sample->season_id);
                syncGuard(str_contains($html, 'data-order-reference-number') && str_contains($html, e($sample->reference_number)), 'Missing order reference in rendered sheet');
            }
            DB::rollBack();
            syncWrite($audit.'/smoke-'.bin2hex(random_bytes(3)).'.json', json_encode($checks, JSON_THROW_ON_ERROR));
            echo json_encode(['status' => 'smoke_ok', 'checks' => $checks, 'business_writes_persisted' => 0, 'public_maintenance_bypassed' => false], JSON_THROW_ON_ERROR).PHP_EOL;

            return;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
    foreach ($before['fingerprints'] as $table => $fingerprint) {
        syncGuard(syncFingerprint($db, $table, null, $fingerprint['columns']) === $fingerprint, 'Business data changed: '.$table);
    }
    $nowApplied = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    syncGuard(array_diff($before['migrations'], $nowApplied) === [] && array_diff($nowApplied, $before['migrations'], productionReleaseMigrations()) === [], 'Unexpected migration history changes');
    $files = array_map(fn ($f) => basename($f, '.php'), glob($root.'/database/migrations/*.php'));
    syncGuard(array_diff($files, $nowApplied) === [], 'Pending production migrations remain');
    if (! in_array(productionReleaseMigrations()[0], $before['migrations'], true)) {
        syncGuard((int) $db->query('SELECT COUNT(*) FROM products WHERE visible_to_partners<>1 OR visible_to_partners IS NULL')->fetchColumn() === 0, 'Existing product visibility was not preserved');
    }
    syncGuard((fileperms($root.'/public') & 0777) === $before['public_mode'], 'Public directory mode changed');
    syncRun([$php, 'artisan', 'up', '--no-interaction'], 'maintenance-up-'.bin2hex(random_bytes(3)), $root, $audit);
    $result = ['status' => 'verified_live', 'commit' => $expected, 'unchanged_business_tables' => count($before['fingerprints']),
        'migrations' => count($nowApplied), 'existing_products_visible' => count($before['product_ids']), 'backup' => $audit.'/database-before.sql.gz'];
    syncWrite($audit.'/verified-'.bin2hex(random_bytes(3)).'.json', json_encode($result, JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if ($downStartedHere && isset($root, $head)) {
        try {
            if (syncRead(['git', 'rev-parse', 'HEAD'], $root) === $head) {
                Artisan::call('up');
            }
        } catch (Throwable $ignored) {
        }
    }
    fwrite(STDERR, 'Production release stopped: '.($error instanceof DomainException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
