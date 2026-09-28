<?php

use App\Contract\Contract;

it('carries the schemas of its pinned tag, and every one parses', function () {
    $version = json_decode(file_get_contents(resource_path('contract/VERSION')), true);
    expect($version['tag'])->toMatch('/^contract-v\d+\.\d+\.\d+$/');
    $files = glob(resource_path('contract/schema/*.json'));
    expect(array_map('basename', $files))->toEqual(array_keys($version['files']));
    foreach ($files as $file) {
        expect(hash_file('sha256', $file))->toBe($version['files'][basename($file)], basename($file).' was edited by hand');
        $schema = json_decode(file_get_contents($file));
        expect($schema->{'$id'})->toBe(Contract::BASE_ID.basename($file));
    }
});

it('checks the copy against the tag in the sibling clone', function () {
    $sibling = base_path('../comitiva');
    if (! is_dir($sibling.'/.git')) {
        $this->markTestSkipped('No comitiva clone next to the hub (CI runs contract:check itself).');
    }
    $this->artisan('contract:check', ['--from' => $sibling])->assertSuccessful();
});
