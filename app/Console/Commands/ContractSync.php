<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('contract:sync {tag : A contract-v* tag of comitiva} {--from= : A local clone of comitiva instead of the remote}')]
#[Description('Copy the contract JSON Schemas from a comitiva tag into resources/contract')]
class ContractSync extends Command
{
    public function handle(): int
    {
        $tag = (string) $this->argument('tag');
        if (! preg_match('/^contract-v\d+\.\d+\.\d+$/', $tag)) {
            $this->error("{$tag} is not a contract-vX.Y.Z tag");

            return self::FAILURE;
        }
        $source = ContractSource::open($this->option('from'), (string) config('services.comitiva.repository'), $tag);
        $files = $source->files($tag);

        $dir = resource_path('contract/schema');
        File::ensureDirectoryExists($dir);
        foreach (File::files($dir) as $existing) {
            if (! isset($files[$existing->getFilename()])) {
                File::delete($existing->getPathname());
            }
        }
        foreach ($files as $name => $contents) {
            File::put("{$dir}/{$name}", $contents);
        }
        File::put(resource_path('contract/VERSION'), self::version($tag, $source->commit($tag), $files));

        $this->info(sprintf('Copied %d schemas from %s.', count($files), $tag));

        return self::SUCCESS;
    }

    /** @param  array<string, string>  $files */
    public static function version(string $tag, string $commit, array $files): string
    {
        $hashes = array_map(fn (string $contents) => hash('sha256', $contents), $files);

        return json_encode(['tag' => $tag, 'commit' => $commit, 'files' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
