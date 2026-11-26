<?php

declare(strict_types=1);
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function syncGuard(bool $ok, string $message): void
{
    if (! $ok) {
        throw new DomainException($message);
    }
}
function syncWrite(string $path, string $data): void
{
    $previousMask = umask(0077);
    try {
        $file = fopen($path, 'xb');
    } finally {
        umask($previousMask);
    }
    syncGuard($file !== false, 'Audit file already exists');
    try {
        syncGuard(fwrite($file, $data) === strlen($data), 'Incomplete audit write');
    } finally {
        fclose($file);
    } chmod($path, 0600);
}
function syncRead(array $command, string $root): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    syncGuard(is_resource($process), 'Cannot start read-only command');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    syncGuard(proc_close($process) === 0, 'Read-only command failed');

    return trim($out);
}
function syncRun(array $command, string $name, string $root, string $audit): string
{
    $out = $audit.'/'.$name.'.stdout';
    $err = $audit.'/'.$name.'.stderr';
    syncGuard(! file_exists($out) && ! file_exists($err), 'Operation log already exists');
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'x'], 2 => ['file', $err, 'x']], $pipes, $root);
    syncGuard(is_resource($process), 'Cannot start operation');
    $exit = proc_close($process);
    chmod($out, 0600);
    chmod($err, 0600);
    syncGuard($exit === 0, 'Operation failed: '.$name.'; inspect private audit logs');

    return trim(file_get_contents($out));
}
function syncFingerprint(PDO $db, string $table, ?array $ids = null, ?array $columns = null): array
{
    $quote = static fn ($v) => '`'.str_replace('`', '``', $v).'`';
    $keys = $db->query('SHOW KEYS FROM '.$quote($table).' WHERE Key_name=\'PRIMARY\'')->fetchAll(PDO::FETCH_ASSOC);
    usort($keys, static fn ($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
    $available = $db->query('SHOW COLUMNS FROM '.$quote($table))->fetchAll(PDO::FETCH_COLUMN);
    $columns ??= $available;
    syncGuard(array_diff($columns, $available) === [], 'An existing database column was removed');
    $order = array_column($keys, 'Column_name') ?: $columns;
    $sql = 'SELECT '.implode(',', array_map($quote, $columns)).' FROM '.$quote($table).($ids === null ? '' : ' WHERE id IN ('.implode(',', array_map('intval', $ids)).')')
        .' ORDER BY '.implode(',', array_map($quote, $order));
    $statement = $db->query($sql);
    $hash = hash_init('sha256');
    $count = 0;
    while ($row = $statement->fetch(PDO::FETCH_NUM)) {
        hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $count++;
    }
    $statement->closeCursor();

    return ['rows' => $count, 'sha256' => hash_final($hash), 'columns' => $columns];
}
function syncBackup(array $cfg, string $root, string $audit, array $invalidViews = []): void
{
    $quote = static fn ($v) => '"'.strtr((string) $v, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
    $client = $audit.'/temporary-backup-client.cnf';
    $text = "[client]\n";
    foreach (['host' => $cfg['host'], 'port' => $cfg['port'], 'user' => $cfg['username'], 'password' => $cfg['password']] as $key => $value) {
        $text .= $key.'='.$quote($value)."\n";
    }
    syncWrite($client, $text);
    try {
        $path = $audit.'/database-before.sql.gz';
        syncGuard(! file_exists($path), 'Backup already exists');
        $gzip = gzopen($path, 'wb6');
        syncGuard($gzip !== false, 'Cannot create compressed backup');
        $arguments = ['/usr/bin/mariadb-dump', '--defaults-extra-file='.$client, '--single-transaction', '--quick', '--skip-lock-tables',
            '--no-tablespaces', '--hex-blob', '--routines', '--triggers', '--events', '--default-character-set=utf8mb4'];
        foreach ($invalidViews as $name => $definition) {
            $arguments[] = '--ignore-table='.$cfg['database'].'.'.$name;
        }
        $arguments[] = $cfg['database'];
        $process = proc_open($arguments,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $audit.'/database-dump.stderr', 'x']], $pipes, $root);
        syncGuard(is_resource($process), 'Cannot start database backup');
        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 1024 * 1024);
            syncGuard($chunk !== false, 'Cannot read backup stream');
            if ($chunk === '') {
                continue;
            } hash_update($hash, $chunk);
            $size += strlen($chunk);
            syncGuard(gzwrite($gzip, $chunk) === strlen($chunk), 'Incomplete compressed backup');
        }
        fclose($pipes[1]);
        $status = proc_close($process);
        foreach ($invalidViews as $name => $definition) {
            $chunk = "\n-- Definition of a pre-existing invalid view; no underlying data is stored in views.\n"
                .'DROP VIEW IF EXISTS `'.str_replace('`', '``', $name)."`;\n".$definition['Create View'].";\n";
            hash_update($hash, $chunk);
            $size += strlen($chunk);
            syncGuard(gzwrite($gzip, $chunk) === strlen($chunk), 'Incomplete view-definition backup');
        }
        gzclose($gzip);
        chmod($path, 0600);
        chmod($audit.'/database-dump.stderr', 0600);
        syncGuard($status === 0 && $size > 100, 'Database backup failed; inspect private log');
        $expected = hash_final($hash);
        $file = gzopen($path, 'rb');
        $actual = hash_init('sha256');
        while (! gzeof($file)) {
            $chunk = gzread($file, 1024 * 1024);
            syncGuard($chunk !== false, 'Cannot verify compressed backup');
            hash_update($actual, $chunk);
        }
        gzclose($file);
        syncGuard(hash_final($actual) === $expected, 'Backup verification failed');
        syncWrite($audit.'/backup.json', json_encode(['file' => $path, 'compressed_bytes' => filesize($path), 'sql_bytes' => $size, 'sql_sha256' => $expected,
            'pre_existing_invalid_views_definition_only' => array_keys($invalidViews)], JSON_THROW_ON_ERROR));
    } finally {
        if (is_file($client)) {
            unlink($client);
        }
    }
}

function preorderReleaseTarget(string $target): array
{
    return match ($target) {
        'Nas' => ['root' => '/var/www/html', 'database' => 'preorder_dev', 'branch' => 'feature/pricing-module'],
        'Staging' => ['root' => '/home/szeganet/preorder-staging-releases/20261029-561a247', 'database' => 'szeganet_preorder_test', 'branch' => 'release/pricing-staging-20261029'],
        default => throw new DomainException('Only NAS and staging are supported; production is never a deployment target'),
    };
}

function preorderAuditMigrationPath(): string
{
    return 'database/migrations/2026_11_26_090000_create_order_status_logs_table.php';
}

function preorderAuditMigrationValidate(string $contents): void
{
    syncGuard(hash('sha256', str_replace("\r\n", "\n", $contents)) === 'b315e15326a85730fdd853efa9adb6c58c0fee1cbfdc89cc12e6e783d7b2c168', 'Reviewed audit migration content changed');
}

function preorderAuditAddedRows(string $table, array $rows, array $existingIds, int $batch): void
{
    syncGuard(in_array($table, ['permissions', 'migrations'], true), 'Unsupported audit migration table');
    $existingIds = array_map('strval', $existingIds);
    $added = array_values(array_filter($rows, static fn ($row) => ! in_array((string) $row['id'], $existingIds, true)));
    syncGuard(count($added) === 1, 'Unexpected added rows: '.$table);
    syncGuard($table === 'permissions'
        ? $added[0]['name'] === 'View:OrderStatusHistory' && $added[0]['guard_name'] === 'web'
        : $added[0]['migration'] === basename(preorderAuditMigrationPath(), '.php') && (int) $added[0]['batch'] === $batch, 'Unexpected migration/permission addition');
}

function preorderReleaseCheckPaths(array $paths, bool $prepareOrderStatusAudit = false): void
{
    foreach ($paths as $path) {
        syncGuard(is_string($path) && $path !== '' && ! str_contains($path, "\n"), 'Invalid changed path');
        if ($prepareOrderStatusAudit && $path === preorderAuditMigrationPath()) {
            continue;
        }
        syncGuard(! preg_match('~^(?:\.env(?:$|\.)|public/(?:\.htaccess|error_log)$|composer\.(?:json|lock)$|package(?:-lock)?\.json$|yarn\.lock$|pnpm-lock\.|vite\.config\.|resources/(?:js|css)/|database/migrations/|infrastructure/(?:docker/|compose\.|docker-compose)|docker-compose|\.github/workflows/|\.gitmodules$|\.codex/|\.agents/)~', $path),
            'Requires separate schema/build/dependency/configuration preparation: '.$path);
    }
}

if (defined('PREORDER_DEPLOY_LIBRARY_ONLY')) {
    return;
}

$releaseOwnsMaintenance = false;
try {
    $target = '__TARGET__';
    $mode = '__MODE__';
    $expected = '__COMMIT__';
    $baseline = '__BASE_COMMIT__';
    $runId = '__RUN_ID__';
    $prepareOrderStatusAudit = '__PREPARE_ORDER_STATUS_AUDIT__' === '1';
    $spec = preorderReleaseTarget($target);
    $nas = $target === 'Nas';
    $root = $spec['root'];
    $database = $spec['database'];
    syncGuard(in_array($mode, ['inspect', 'backup', 'deploy', 'verify', 'health'], true), 'Unsupported deployment mode');
    syncGuard((bool) preg_match('/^[a-f0-9]{40}$/D', $expected) && (bool) preg_match('/^[a-f0-9]{40}$/D', $baseline), 'Invalid commit');
    syncGuard((bool) preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D', $runId), 'Invalid deployment identifier');
    if (! $nas) {
        syncGuard(realpath($root) === $root && ! is_link($root), 'Unexpected staging path');
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    } else {
        $app = app();
    }
    syncGuard(base_path() === $root && config('database.default') === 'mysql', 'Unexpected application root or driver');
    $cfg = config('database.connections.mysql');
    syncGuard($cfg['database'] === $database && DB::selectOne('SELECT DATABASE() AS name')->name === $database, 'Wrong database');
    if (! $nas) {
        $env = Dotenv::parse(file_get_contents($root.'/.env'));
        syncGuard($app->environment('staging') && ($env['APP_ENV'] ?? '') === 'staging' && ($env['DB_DATABASE'] ?? '') === $database
            && ($env['DB_USERNAME'] ?? '') === 'szeganet_po_test' && empty($env['DB_URL']) && config('mail.default') === 'log'
            && parse_url(config('app.url'), PHP_URL_HOST) === 'teszt-rendeles.szeganet.hu', 'Staging isolation check failed');
    }
    $db = DB::connection()->getPdo();
    if (! $nas) {
        foreach ($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            syncGuard((bool) preg_match('/^GRANT (.+?) ON (.+?) TO /', $grant, $matches), 'Unexpected grants');
            $scope = str_replace([chr(96), '\\'], '', $matches[2]);
            syncGuard(($matches[1] === 'USAGE' && $scope === '*.*') || $scope === $database.'.*', 'Staging privileges exceed its own database');
        }
    }
    $origin = syncRead(['git', 'remote', 'get-url', 'origin'], $root);
    syncGuard((bool) preg_match('~^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)szentkatg/preorder(?:\.git)?/?$~', $origin), 'Unexpected repository');
    $installedBranch = syncRead(['git', 'branch', '--show-current'], $root);
    // Staging releases are intentionally pinned in detached HEAD; their source branch remains fixed.
    syncGuard($installedBranch === $spec['branch'] || (! $nas && $installedBranch === ''), 'Unexpected deployed branch: '.($installedBranch ?: '(detached HEAD)'));
    $head = syncRead(['git', 'rev-parse', 'HEAD'], $root);
    $dirty = array_values(array_filter(array_map('trim', explode("\n", syncRead(['git', 'status', '--porcelain', '--untracked-files=no'], $root)))));
    syncGuard($nas ? $dirty === [] : array_diff($dirty, ['M public/.htaccess', 'M public/error_log']) === [], 'Tracked remote modifications must be reviewed');
    $files = array_map(static fn ($path) => basename($path, '.php'), glob($root.'/database/migrations/*.php'));
    $applied = $db->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $pending = array_values(array_diff($files, $applied));
    $auditRoot = $nas ? $root.'/storage/app/private/release-audit' : '/home/szeganet/preorder-staging-releases/.release-audit';
    $audit = $auditRoot.'/preorder-'.$runId.'-'.substr($expected, 0, 12);
    if ($mode === 'inspect') {
        $resume = is_file($audit.'/before.json') ? json_decode(file_get_contents($audit.'/before.json'), true, flags: JSON_THROW_ON_ERROR) : null;
        echo json_encode(['status' => 'checked', 'environment' => $target, 'head' => $head, 'database' => $database,
            'maintenance' => $app->isDownForMaintenance(), 'pending_migrations' => $pending, 'dump_available' => is_executable('/usr/bin/mariadb-dump'),
            'resume_baseline' => $resume['head'] ?? null, 'verified' => is_file($audit.'/verified.json')], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    if ($mode === 'health') {
        syncGuard($head === $expected && ! $app->isDownForMaintenance() && $pending === [], 'Installed version, maintenance or migrations not ready');
        config(['session.driver' => 'array', 'cache.default' => 'array']);
        $checks = [];
        if ($prepareOrderStatusAudit) {
            $db->query('SELECT id, order_id, event, occurred_at, email_status FROM order_status_logs LIMIT 0')->closeCursor();
            syncGuard((int) $db->query("SELECT COUNT(*) FROM permissions WHERE name='View:OrderStatusHistory' AND guard_name='web'")->fetchColumn() === 1, 'Audit permission missing');
            $checks[] = ['audit_schema_and_permission' => true];
        }
        if ($nas) {
            $web = posix_getpwnam('www-data');
            syncGuard($web !== false, 'Missing web-worker account');
            foreach (glob(storage_path('framework/views/*.php')) as $file) {
                $s = stat($file);
                $readable = $s['uid'] === $web['uid'] ? ($s['mode'] & 0400) : ($s['gid'] === $web['gid'] ? ($s['mode'] & 0040) : ($s['mode'] & 0004));
                syncGuard((bool) $readable, 'A compiled view is not readable by the web worker');
            }
            foreach (['/admin/login', '/partner/login'] as $path) {
                $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0,
                    'header' => "Host: ds.szeganet.hu:8089\r\n"]]);
                $body = file_get_contents('http://preorder-v2-dev-nginx'.$path, false, $context);
                preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $match);
                syncGuard(($match[1] ?? '') === '200' && $body !== false && $body !== '', 'Nginx/FPM login HTTP check failed');
                $checks[] = ['path' => $path, 'status' => 200];
            }
        } else {
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            foreach (['/admin/login', '/partner/login'] as $path) {
                $request = Request::create(rtrim(config('app.url'), '/').$path, 'GET');
                $response = $kernel->handle($request);
                syncGuard($response->getStatusCode() === 200 && strlen($response->getContent()) > 100, 'Staging application login rendering failed');
                $checks[] = ['path' => $path, 'status' => $response->getStatusCode()];
                $kernel->terminate($request, $response);
            }
            $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0]]);
            $body = file_get_contents('https://teszt-rendeles.szeganet.hu/admin/login', false, $context);
            preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $match);
            syncGuard(($match[1] ?? '') === '401', 'Staging directory protection HTTP check failed');
            $checks[] = ['path' => 'public HTTPS /admin/login', 'status' => 401];
        }
        echo json_encode(['status' => 'healthy', 'environment' => $target, 'head' => $head, 'checks' => $checks], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    $lock = fopen(sys_get_temp_dir().'/preorder-'.strtolower($target).'-release.lock', 'c');
    syncGuard($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Another release operation is in progress');
    // Compiled views must remain readable by FPM, including when the NAS runner is root.
    umask(0022);
    $php = $nas ? PHP_BINARY : '/opt/alt/php83/usr/bin/php';
    syncGuard($pending === [], 'Pending migrations require separate preparation');
    if ($mode === 'backup') {
        syncGuard($head === $baseline && ! $app->isDownForMaintenance(), 'Server changed or already in maintenance');
        syncGuard(! file_exists($audit) && is_executable('/usr/bin/mariadb-dump'), 'Audit already exists or dump executable missing');
        syncRead(['git', 'fetch', '--no-tags', 'origin', 'refs/heads/'.$spec['branch']], $root);
        syncGuard(syncRead(['git', 'rev-parse', 'FETCH_HEAD'], $root) === $expected, 'Published target changed');
        $changes = array_values(array_filter(explode("\n", syncRead(['git', 'diff', '--name-only', $baseline, $expected], $root))));
        preorderReleaseCheckPaths($changes, $prepareOrderStatusAudit);
        $prepareAudit = $prepareOrderStatusAudit && in_array(preorderAuditMigrationPath(), $changes, true);
        $migrationPreparation = null;
        if ($prepareAudit) {
            preorderAuditMigrationValidate(syncRead(['git', 'show', $expected.':'.preorderAuditMigrationPath()], $root)."\n");
            syncGuard(! in_array('order_status_logs', $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), true), 'Audit table already exists; review baseline');
            syncGuard((int) $db->query("SELECT COUNT(*) FROM permissions WHERE name='View:OrderStatusHistory' AND guard_name='web'")->fetchColumn() === 0, 'Audit permission already exists; review baseline');
            $migrationPreparation = [
                'permissions' => $db->query('SELECT id FROM permissions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
                'migrations' => $db->query('SELECT id FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
                'batch' => (int) $db->query('SELECT MAX(batch) FROM migrations')->fetchColumn() + 1,
            ];
        }
        if (! is_dir($auditRoot)) {
            syncGuard(mkdir($auditRoot, 0700, true), 'Cannot create private audit root');
        }
        syncGuard(! is_link($auditRoot) && mkdir($audit, 0700), 'Cannot create private release audit');
        syncRun([$php, 'artisan', 'down', '--retry=30', '--no-interaction'], 'maintenance-down', $root, $audit);
        $releaseOwnsMaintenance = true;
        if ($migrationPreparation !== null) {
            foreach (['permissions', 'migrations'] as $table) {
                $migrationPreparation[$table] = $db->query('SELECT id FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            }
            $migrationPreparation['batch'] = (int) $db->query('SELECT MAX(batch) FROM migrations')->fetchColumn() + 1;
        }
        $volatile = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'imports', 'exports', 'failed_import_rows'];
        $fingerprints = [];
        foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (! in_array($table, $volatile, true)) {
                $fingerprints[$table] = syncFingerprint($db, $table);
            }
        }
        $views = [];
        $invalidViews = [];
        foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='VIEW'")->fetchAll(PDO::FETCH_COLUMN) as $view) {
            $quoted = chr(96).str_replace(chr(96), chr(96).chr(96), $view).chr(96);
            $views[$view] = $db->query('SHOW CREATE VIEW '.$quoted)->fetch(PDO::FETCH_ASSOC);
            try {
                $db->query('SELECT * FROM '.$quoted.' LIMIT 0')->closeCursor();
            } catch (PDOException $error) {
                syncGuard((int) ($error->errorInfo[1] ?? 0) === 1356, 'Unexpected existing view error');
                $invalidViews[$view] = $views[$view];
            }
        }
        $before = ['head' => $head, 'target' => $expected, 'fingerprints' => $fingerprints, 'view_definitions' => $views,
            'migration_preparation' => $migrationPreparation,
            'tables' => $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
            'env_sha256' => hash_file('sha256', $root.'/.env'), 'htaccess_sha256' => hash_file('sha256', $root.'/public/.htaccess'),
            'production_head' => $nas ? null : syncRead(['git', 'rev-parse', 'HEAD'], '/home/szeganet/preorder-production-git')];
        syncWrite($audit.'/before.json', json_encode($before, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        syncWrite($audit.'/public-htaccess.before', file_get_contents($root.'/public/.htaccess'));
        syncBackup($cfg, $root, $audit, $invalidViews);
        echo json_encode(['status' => 'backup_ready', 'environment' => $target, 'run_id' => $runId, 'audit' => $audit], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    syncGuard(is_file($audit.'/before.json') && is_file($audit.'/backup.json'), 'Verified backup is required');
    $before = json_decode(file_get_contents($audit.'/before.json'), true, flags: JSON_THROW_ON_ERROR);
    syncGuard($before['target'] === $expected && $before['head'] === $baseline, 'Backup does not match this release');
    $migrationPreparation = $before['migration_preparation'] ?? null;
    syncGuard($migrationPreparation === null || $prepareOrderStatusAudit, 'This release requires the explicit audit migration option');
    if ($mode === 'deploy') {
        syncGuard($head === $baseline && $app->isDownForMaintenance(), 'Server changed after backup');
        syncGuard(syncRead(['git', 'rev-parse', 'FETCH_HEAD'], $root) === $expected, 'Fetched target changed');
        preorderReleaseCheckPaths(array_values(array_filter(explode("\n", syncRead(['git', 'diff', '--name-only', $baseline, $expected], $root)))), $prepareOrderStatusAudit);
        if ($migrationPreparation !== null) {
            preorderAuditMigrationValidate(syncRead(['git', 'show', $expected.':'.preorderAuditMigrationPath()], $root)."\n");
        }
        syncRun(['git', 'merge', '--ff-only', $expected], 'fast-forward', $root, $audit);
        syncRun([$php, 'artisan', 'optimize:clear', '--no-interaction'], 'optimize-clear', $root, $audit);
        if ($migrationPreparation !== null) {
            preorderAuditMigrationValidate(file_get_contents($root.'/'.preorderAuditMigrationPath()));
            syncRun([$php, 'artisan', 'migrate', '--path='.preorderAuditMigrationPath(), '--force', '--no-interaction'], 'audit-migration', $root, $audit);
        }
        if (array_key_exists('filament:optimize-clear', Artisan::all())) {
            syncRun([$php, 'artisan', 'filament:optimize-clear', '--no-interaction'], 'filament-clear', $root, $audit);
        }
        syncRun([$php, 'artisan', 'optimize', '--no-interaction'], 'optimize', $root, $audit);
        syncWrite($audit.'/installed.json', json_encode(['commit' => $expected], JSON_THROW_ON_ERROR));
        echo json_encode(['status' => 'installed', 'environment' => $target, 'head' => $expected], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    syncGuard($head === $expected && is_file($audit.'/installed.json'), 'Installation must complete before verification');
    syncGuard(hash_file('sha256', $root.'/.env') === $before['env_sha256'] && hash_file('sha256', $root.'/public/.htaccess') === $before['htaccess_sha256'], 'Environment or directory protection changed');
    if (! $nas) {
        syncGuard(syncRead(['git', 'rev-parse', 'HEAD'], '/home/szeganet/preorder-production-git') === $before['production_head'], 'Production code changed during staging release');
    }
    foreach ($before['fingerprints'] as $table => $fingerprint) {
        $ids = $migrationPreparation[$table] ?? null;
        // Empty old tables still need an empty-set fingerprint (never WHERE id IN ()).
        $actual = $ids === [] ? ['rows' => 0, 'sha256' => hash('sha256', ''), 'columns' => $fingerprint['columns']]
            : syncFingerprint($db, $table, $ids, $fingerprint['columns']);
        syncGuard($actual === $fingerprint, 'Existing data changed: '.$table);
    }
    if ($migrationPreparation !== null) {
        foreach (['permissions', 'migrations'] as $table) {
            $rows = $db->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            preorderAuditAddedRows($table, $rows, $migrationPreparation[$table], $migrationPreparation['batch']);
        }
        $expectedTables = [...$before['tables'], 'order_status_logs'];
        $actualTables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($expectedTables);
        sort($actualTables);
        syncGuard($expectedTables === $actualTables && (int) $db->query('SELECT COUNT(*) FROM order_status_logs')->fetchColumn() === 0, 'Unexpected schema or initial audit data');
    }
    foreach ($before['view_definitions'] as $view => $definition) {
        $quoted = chr(96).str_replace(chr(96), chr(96).chr(96), $view).chr(96);
        syncGuard($db->query('SHOW CREATE VIEW '.$quoted)->fetch(PDO::FETCH_ASSOC) === $definition, 'Existing view changed');
    }
    if (! is_file($audit.'/verified.json')) {
        syncWrite($audit.'/verified.json', json_encode(['commit' => $expected, 'unchanged_tables' => count($before['fingerprints'])], JSON_THROW_ON_ERROR));
    }
    syncRun([$php, 'artisan', 'up', '--no-interaction'], 'maintenance-up-'.bin2hex(random_bytes(3)), $root, $audit);
    echo json_encode(['status' => 'verified_live', 'environment' => $target, 'head' => $head,
        'business_tables_unchanged' => count($before['fingerprints']), 'backup' => $audit.'/database-before.sql.gz'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if ($releaseOwnsMaintenance && isset($root, $baseline)) {
        try {
            if (syncRead(['git', 'rev-parse', 'HEAD'], $root) === $baseline) {
                Artisan::call('up');
            }
        } catch (Throwable $ignored) {
        }
    }
    fwrite(STDERR, 'Release stopped: '.($error instanceof DomainException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
