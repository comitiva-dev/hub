<?php

namespace App\Console\Commands;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads `packages/contract/schema/*.json` from a tag of the comitiva
 * repository: a local clone (`--from`), or a fresh clone of the remote. Files
 * come from the tag's tree, never from a working copy (ADR 0016).
 */
class ContractSource
{
    private const SCHEMA_DIR = 'packages/contract/schema';

    private ?string $tempDir = null;

    private function __construct(private readonly string $repository) {}

    public static function open(?string $from, string $remote, string $tag): self
    {
        if ($from !== null) {
            if (! is_dir($from)) {
                throw new RuntimeException("No repository at {$from}");
            }

            return new self(realpath($from) ?: $from);
        }
        $dir = sys_get_temp_dir().'/hub-contract-'.bin2hex(random_bytes(6));
        self::run(['git', 'clone', '--quiet', '--depth', '1', '--branch', $tag, $remote, $dir]);
        $source = new self($dir);
        $source->tempDir = $dir;

        return $source;
    }

    public function __destruct()
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            (new Process(['rm', '-rf', $this->tempDir]))->run();
        }
    }

    /** The commit the tag points at. */
    public function commit(string $tag): string
    {
        return trim(self::run(['git', '-C', $this->repository, 'rev-parse', '--verify', "{$tag}^{commit}"]));
    }

    /**
     * @return array<string, string> file name => contents
     */
    public function files(string $tag): array
    {
        $listing = self::run(['git', '-C', $this->repository, 'ls-tree', '--name-only', "{$tag}:".self::SCHEMA_DIR]);
        $files = [];
        foreach (array_filter(explode("\n", $listing)) as $name) {
            if (! str_ends_with($name, '.json')) {
                continue;
            }
            $files[$name] = self::run(['git', '-C', $this->repository, 'show', "{$tag}:".self::SCHEMA_DIR."/{$name}"]);
        }
        if ($files === []) {
            throw new RuntimeException("No schemas under {$tag}:".self::SCHEMA_DIR);
        }
        ksort($files);

        return $files;
    }

    /** @param  list<string>  $command */
    private static function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'git failed: '.implode(' ', $command));
        }

        return $process->getOutput();
    }
}
