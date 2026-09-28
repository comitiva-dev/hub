<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('contract:check {--from= : A local clone of comitiva instead of the remote}')]
#[Description('Fail when resources/contract differs from the pinned comitiva tag')]
class ContractCheck extends Command
{
    public function handle(): int
    {
        $versionFile = resource_path('contract/VERSION');
        $version = is_file($versionFile) ? json_decode((string) file_get_contents($versionFile), true) : null;
        if (! is_array($version) || ! is_string($version['tag'] ?? null)) {
            $this->error('resources/contract/VERSION is missing; run contract:sync.');

            return self::FAILURE;
        }
        $tag = $version['tag'];

        $source = ContractSource::open($this->option('from'), (string) config('services.comitiva.repository'), $tag);
        $expected = $source->files($tag);
        $problems = [];

        if (($version['commit'] ?? null) !== $source->commit($tag)) {
            $problems[] = "{$tag} now points at another commit";
        }
        $dir = resource_path('contract/schema');
        $present = array_map(fn ($f) => $f->getFilename(), File::files($dir));
        foreach ($expected as $name => $contents) {
            if (! in_array($name, $present, true)) {
                $problems[] = "{$name} is missing";
            } elseif (File::get("{$dir}/{$name}") !== $contents) {
                $problems[] = "{$name} differs from {$tag}";
            }
        }
        foreach ($present as $name) {
            if (! isset($expected[$name])) {
                $problems[] = "{$name} is not in {$tag}";
            }
        }
        if (File::get($versionFile) !== ContractSync::version($tag, $source->commit($tag), $expected)) {
            $problems[] = 'VERSION does not match the files of '.$tag;
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            $this->line("Run: php artisan contract:sync {$tag}");

            return self::FAILURE;
        }
        $this->info("resources/contract matches {$tag}.");

        return self::SUCCESS;
    }
}
