<?php

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('stores sniffed images and UTF-8 text, and serves them to members only', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    actingAsToken($ana);
    $png = "\x89PNG\r\n\x1a\n".str_repeat("\0", 16);

    $image = $this->postJson("/api/v1/workspaces/{$ws->id}/attachments", [
        'name' => 'chart.png', 'mediaType' => 'application/octet-stream', 'dataBase64' => base64_encode($png),
    ])->assertCreated();
    expectContract($image, 'AttachmentBlock');
    expect($image->json())->toMatchArray(['type' => 'image', 'name' => 'chart.png']);
    expect($image->json('source'))->toMatchArray(['kind' => 'file', 'mediaType' => 'image/png']);

    $doc = $this->postJson("/api/v1/workspaces/{$ws->id}/attachments", [
        'name' => 'notas.md', 'mediaType' => 'text/markdown', 'dataBase64' => base64_encode("# Notas\nação"),
    ])->assertCreated();
    expectContract($doc, 'AttachmentBlock');
    expect($doc->json())->toMatchArray(['type' => 'document', 'mediaType' => 'text/markdown']);

    $id = $image->json('source.path');
    $served = $this->get("/api/v1/attachments/{$id}")->assertOk();
    expect($served->headers->get('Content-Type'))->toBe('image/png');
    expect($served->streamedContent())->toBe($png);

    actingAsToken(user('Eve'));
    expectError($this->getJson("/api/v1/attachments/{$id}"), 404, 'not_found');
    expect(Attachment::count())->toBe(2);
});

it('refuses binaries, fake images and files over the limits', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    actingAsToken($ana);
    $post = fn (string $name, string $type, string $bytes) => $this->postJson("/api/v1/workspaces/{$ws->id}/attachments", [
        'name' => $name, 'mediaType' => $type, 'dataBase64' => base64_encode($bytes),
    ]);
    expectError($post('a.exe', 'application/x-msdownload', "MZ\0\0"), 415, 'unsupported_attachment');
    expectError($post('fake.png', 'image/png', 'not an image'), 415, 'unsupported_attachment');
    expectError($post('nul.txt', 'text/plain', "a\0b"), 415, 'unsupported_attachment');
    expectError($post('big.txt', 'text/plain', str_repeat('a', 1024 * 1024 + 1)), 413, 'attachment_too_large');
    expect(Attachment::count())->toBe(0);
});
