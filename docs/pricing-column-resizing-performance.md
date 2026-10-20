# Pricing column resizing: mutation feedback fix

The shared inline resizer in `pricing-cost-calculations.blade.php` previously
suppressed mutation callbacks for 150 ms starting before initialization. On a
slow, wide table initialization could exceed that interval. Removing and adding
resize handles then triggered another initialization, repeatedly.

The observer is now disconnected throughout initialization and reconnected in
`finally`. Only added/removed table structure schedules an observer-driven
refresh; text, icons, action menus and resize handles do not. Livewire morph,
navigation and column-manager hooks remain debounced. Row/cell layouts and span
checks are collected once per initialization rather than once per column, and
the initial total table width is written once.

Saved per-user widths, percent-column minimums, column reordering, paging and
width reset remain supported. This change does not simplify or remove the
separate product workspace menu.

## Verification

The dependency-free JavaScript harness executes the actual inline script with
controlled mutation delivery and a simulated slow table (80 columns, 100 data
rows). It covers feedback-loop prevention, linear span checks, nonstructural
mutations, column insertion/reordering, paging, drag persistence, reset and
navigation between saved layouts.

From the pricing worktree, with Node available:

```powershell
node --input-type=module -e "import('./tests/JavaScript/pricing-column-resizing.mjs').then(m => console.log(m.runPricingColumnResizingTests()))"
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/PricingDecimalPreferencesTest.php tests/Feature/PricingPayloadPerformanceTest.php
git diff --check
```

The original HEAD script fails seven of the eight JavaScript cases, including
the self-triggering loop; the fixed script passes all eight. This is a controlled
regression test, not a live browser performance measurement.

The supplied Firefox `516108.fxsnapshot` was inspected locally and read-only,
using Mozilla's CoreDump protobuf framing. Its 485,407 heap nodes sum to
92,458,353 shallow bytes (about 88.2 MiB). No nodes contain allocation stacks.
This is neither total browser RAM nor a CPU timeline, and one snapshot alone
does not establish a memory leak or identify the freezing code path. No heap
contents, session data or snapshot files are committed.

## Deployment

For this resizer fix alone: no migration, Composer install, frontend build,
manual asset upload or environment change is required. Clear/rebuild Laravel
caches after updating the code. Other pending pricing work includes a separate
membership migration and conflict preflight; those requirements still apply
when deploying the combined changes.

After review, commit only the intended files and push the pricing branch:

```powershell
git add -- resources/views/filament/pages/pricing-cost-calculations.blade.php tests/JavaScript/pricing-column-resizing.mjs docs/pricing-column-resizing-performance.md
git commit -m "Fix pricing column resizer mutation feedback loop"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Production cache commands, only after a reviewed production release (do not
pull the entire pricing feature branch into production without release review):

```bash
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
