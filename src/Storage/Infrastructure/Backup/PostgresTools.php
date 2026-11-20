<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Runs the PostgreSQL client tools for backups (ADR 038, "PostgreSQL tools").
 *
 * BACKUP_PG_TOOLS=local (default, the server) runs pg_dump from PATH against the configured connection, with the
 * password passed through PGPASSWORD and never as an argument. BACKUP_PG_TOOLS=docker (local development) runs
 * the tools inside the Docker Compose service `database` and copies files in and out with `docker compose cp`;
 * COMPOSE_PROJECT_NAME selects the project when the command runs from a git worktree.
 *
 * Host, port, user and password come from DATABASE_URL; the database name is asked from the open connection
 * (`current_database()`), so the test suffix (dbname_suffix) and the snapshot of PostgresDatabaseDumper always
 * refer to the same database. Every command is an argument list; nothing goes through a shell.
 *
 * @psalm-suppress ClassMustBeFinal Not final: PostgresDatabaseDumperTest replaces it, because CI has no client
 *                 tools of the server version; Psalm does not analyse tests/.
 */
readonly class PostgresTools
{
    public const string LOCAL = 'local';

    public const string DOCKER = 'docker';

    private const string DOCKER_SERVICE = 'database';

    /** Tables PostGIS keeps in schema public; they belong to the extension, not to the application. */
    public const array POSTGIS_TABLES = ['spatial_ref_sys', 'geometry_columns', 'geography_columns'];

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Connection $connection,
        #[Autowire(env: 'resolve:DATABASE_URL')]
        private string $databaseUrl,
        #[Autowire(param: 'app.backup.pg_tools')]
        private string $mode,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function mode(): string
    {
        if (!\in_array($this->mode, [self::LOCAL, self::DOCKER], true)) {
            throw new BackupFailed(sprintf('BACKUP_PG_TOOLS is "%s".', $this->mode), 'Set BACKUP_PG_TOOLS to "local" (pg_dump on PATH) or "docker" (Docker Compose service "database").');
        }

        return $this->mode;
    }

    public function target(): string
    {
        $params = $this->parameters();

        return sprintf('%s on %s:%d', $params['dbname'], $params['host'] ?? 'localhost', $params['port'] ?? 5432);
    }

    /**
     * @throws BackupFailed when the tool cannot be run
     */
    public function version(string $tool): string
    {
        $process = $this->process([...$this->prefix(), $tool, '--version']);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new BackupFailed(sprintf('%s could not be run (%s mode).', $tool, $this->mode()), self::LOCAL === $this->mode ? sprintf('Install the PostgreSQL client tools (%s on PATH, same major version as the server or newer), or set BACKUP_PG_TOOLS=docker for the local Docker database.', $tool) : 'Start the database with "docker compose up -d database"; from a git worktree also set COMPOSE_PROJECT_NAME to the project of the main checkout.');
        }

        return trim($process->getOutput());
    }

    /**
     * Dumps schema public in custom format into $targetFile, reading the exported snapshot $snapshot.
     *
     * @throws BackupFailed
     */
    public function dump(string $snapshot, string $targetFile): void
    {
        $arguments = ['--format=custom', '--schema=public', '--no-password', '--snapshot='.$snapshot];
        foreach (self::POSTGIS_TABLES as $table) {
            $arguments[] = '--exclude-table=public.'.$table;
        }

        if (self::DOCKER === $this->mode()) {
            $containerFile = '/tmp/ivena-backup-'.bin2hex(random_bytes(8)).'.dump';
            try {
                $this->mustRun([...$this->prefix(), 'pg_dump', ...$this->dockerConnectionArguments(), ...$arguments, '--file='.$containerFile], 'pg_dump');
                $this->mustRun(['docker', 'compose', 'cp', self::DOCKER_SERVICE.':'.$containerFile, $targetFile], 'docker compose cp');
            } finally {
                $this->process([...$this->prefix(), 'rm', '-f', $containerFile])->run();
            }

            return;
        }

        $this->mustRun(['pg_dump', ...$this->localConnectionArguments(), ...$arguments, '--file='.$targetFile], 'pg_dump', $this->passwordEnvironment());
    }

    /**
     * @return list<string>
     */
    private function prefix(): array
    {
        return self::DOCKER === $this->mode() ? ['docker', 'compose', 'exec', '-T', self::DOCKER_SERVICE] : [];
    }

    /**
     * @return list<string>
     */
    private function localConnectionArguments(): array
    {
        $params = $this->parameters();
        $arguments = [];
        if (isset($params['host'])) {
            $arguments[] = '--host='.$params['host'];
        }
        if (isset($params['port'])) {
            $arguments[] = '--port='.$params['port'];
        }
        if (isset($params['user'])) {
            $arguments[] = '--username='.$params['user'];
        }
        $arguments[] = '--dbname='.$params['dbname'];

        return $arguments;
    }

    /**
     * Inside the container the tools connect through the local socket, as the existing scripts do.
     *
     * @return list<string>
     */
    private function dockerConnectionArguments(): array
    {
        $params = $this->parameters();

        return ['--username='.($params['user'] ?? 'app'), '--dbname='.$params['dbname']];
    }

    /**
     * @return array<string, string>
     */
    private function passwordEnvironment(): array
    {
        $password = $this->parameters()['password'] ?? null;

        return null === $password || '' === $password ? [] : ['PGPASSWORD' => $password];
    }

    /**
     * @return array{host: ?string, port: ?int, user: ?string, password: ?string, dbname: string}
     */
    private function parameters(): array
    {
        $params = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql'])->parse($this->databaseUrl);

        try {
            $database = (string) $this->connection->fetchOne('SELECT current_database()');
        } catch (\Throwable $exception) {
            throw new BackupFailed('The database is not reachable.', 'Check DATABASE_URL and that PostgreSQL is running.', previous: $exception);
        }

        return [
            'host' => isset($params['host']) ? (string) $params['host'] : null,
            'port' => isset($params['port']) ? (int) $params['port'] : null,
            'user' => isset($params['user']) ? (string) $params['user'] : null,
            'password' => isset($params['password']) ? (string) $params['password'] : null,
            'dbname' => $database,
        ];
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @throws BackupFailed
     */
    private function mustRun(array $command, string $label, array $environment = []): void
    {
        $process = $this->process($command, $environment);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new BackupFailed(sprintf('%s failed with exit code %d: %s', $label, (int) $process->getExitCode(), $this->excerpt($process->getErrorOutput())), 'Check that the database is reachable and that the client tools match the server version.');
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function process(array $command, array $environment = []): Process
    {
        $process = new Process($command, $this->projectDir, $environment);
        $process->setTimeout(null);

        return $process;
    }

    private function excerpt(string $errorOutput): string
    {
        $errorOutput = trim($errorOutput);

        return '' === $errorOutput ? '(no error output)' : mb_substr($errorOutput, 0, 500);
    }
}
