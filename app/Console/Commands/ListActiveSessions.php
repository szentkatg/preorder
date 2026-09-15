<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ListActiveSessions extends Command
{
    protected $signature = 'admin:active-sessions
        {--minutes=30 : Ennyi percen belüli aktivitást mutasson}
        {--limit=50 : Maximum ennyi sort listázzon}';

    protected $description = 'List active Laravel sessions before maintenance or deployment';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $driver = (string) config('session.driver', 'database');

        return match ($driver) {
            'database' => $this->listDatabaseSessions($minutes, $limit),
            'file' => $this->listFileSessions($minutes, $limit),
            default => $this->unsupportedDriver($driver),
        };
    }

    protected function listDatabaseSessions(int $minutes, int $limit): int
    {
        $table = (string) config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            $this->components->error("A sessions tábla nem található: {$table}");

            return self::FAILURE;
        }

        $threshold = now()->subMinutes($minutes)->timestamp;

        $sessions = DB::table($table)
            ->where('last_activity', '>=', $threshold)
            ->orderByDesc('last_activity')
            ->limit($limit)
            ->get([
                'id',
                'user_id',
                'ip_address',
                'user_agent',
                'last_activity',
            ]);

        $this->components->info("Aktív sessionök az elmúlt {$minutes} percben: ".$sessions->count());

        if ($sessions->isEmpty()) {
            return self::SUCCESS;
        }

        $this->table(
            ['Session', 'User ID', 'IP', 'Utolsó aktivitás', 'Perc', 'User agent'],
            $sessions->map(fn ($session) => [
                Str::limit((string) $session->id, 18, '…'),
                $session->user_id ?? '-',
                $session->ip_address ?? '-',
                CarbonImmutable::createFromTimestamp((int) $session->last_activity)->format('Y-m-d H:i:s'),
                (int) CarbonImmutable::createFromTimestamp((int) $session->last_activity)->diffInMinutes(now()),
                Str::limit((string) ($session->user_agent ?? '-'), 80),
            ])->all()
        );

        return self::SUCCESS;
    }

    protected function listFileSessions(int $minutes, int $limit): int
    {
        $path = (string) config('session.files');

        if (! File::isDirectory($path)) {
            $this->components->error("A session könyvtár nem található: {$path}");

            return self::FAILURE;
        }

        $threshold = now()->subMinutes($minutes)->timestamp;

        $files = collect(File::files($path))
            ->filter(fn ($file) => $file->getMTime() >= $threshold)
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->take($limit)
            ->values();

        $this->components->info("Aktív session fájlok az elmúlt {$minutes} percben: ".$files->count());

        if ($files->isEmpty()) {
            return self::SUCCESS;
        }

        $this->table(
            ['Session fájl', 'Utolsó aktivitás', 'Perc'],
            $files->map(fn ($file) => [
                Str::limit($file->getFilename(), 40, '…'),
                CarbonImmutable::createFromTimestamp($file->getMTime())->format('Y-m-d H:i:s'),
                (int) CarbonImmutable::createFromTimestamp($file->getMTime())->diffInMinutes(now()),
            ])->all()
        );

        return self::SUCCESS;
    }

    protected function unsupportedDriver(string $driver): int
    {
        $this->components->warn("A session driver jelenleg '{$driver}', ehhez nincs részletes session lista.");

        return self::SUCCESS;
    }
}
