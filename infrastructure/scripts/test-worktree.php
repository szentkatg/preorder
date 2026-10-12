<?php

use PHPUnit\TextUI\Application;

// Run tests against this worktree even when vendor is a junction to another checkout.
$root = dirname(__DIR__, 2);
$loader = require $root.'/vendor/autoload.php';
$loader->setPsr4('App\\', [$root.'/app']);
$loader->setPsr4('Tests\\', [$root.'/tests']);
$overrides = [];
foreach ($loader->getClassMap() as $class => $path) {
    foreach (['App\\' => '/app/', 'Tests\\' => '/tests/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $current = $root.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($current)) {
                $overrides[$class] = $current;
            }
        }
    }
}
$loader->addClassMap($overrides);
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $root;
putenv('APP_BASE_PATH='.$root);
chdir($root);

exit((new Application)->run(array_merge(['phpunit'], array_slice($argv, 1))));
