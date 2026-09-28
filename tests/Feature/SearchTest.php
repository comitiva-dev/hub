<?php

use App\Support\Iso;

it('finds titles and message text across accents, the last word as a prefix', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $agent = sharedAgent($ws);
    $c = conversationIn($ws, $agent, ['title' => 'Relatório de ações']);
    messageIn($c, 1, 'user', 'Precisamos revisar a configuração do servidor');
    messageIn($c, 2, 'assistant', 'A configuracao foi revisada', 'streaming');
    $archived = conversationIn($ws, $agent, ['title' => 'Relatorio antigo', 'archived' => true]);
    messageIn($archived, 1, 'user', 'configuração antiga');
    $elsewhere = conversationIn(workspace($ana, 'Other'));
    messageIn($elsewhere, 1, 'user', 'configuração alheia');
    actingAsToken($ana);

    $result = $this->getJson("/api/v1/workspaces/{$ws->id}/search?query=relatorio")->assertOk();
    expectContract($result, 'SearchResult');
    expect(array_column($result->json('conversations'), 'conversationId'))->toBe([$c->id]);

    $hits = $this->getJson("/api/v1/workspaces/{$ws->id}/search?query=".urlencode('revisar configura'))->json('messages');
    expect($hits)->toHaveCount(1);
    expect($hits[0])->toMatchArray(['conversationId' => $c->id, 'seq' => 1, 'role' => 'user', 'conversationTitle' => 'Relatório de ações']);
    $matched = implode('', array_column(array_filter($hits[0]['snippet'], fn ($p) => $p['match']), 'text'));
    expect($matched)->toContain('configuração');

    // Operators typed by the user are plain text; nothing but letters and digits reaches to_tsquery.
    $this->getJson("/api/v1/workspaces/{$ws->id}/search?query=".urlencode("config & ! ( ':*"))->assertOk();
    expect($this->getJson("/api/v1/workspaces/{$ws->id}/search?query=".urlencode('!!'))->json('messages'))->toBe([]);
    expectError($this->getJson("/api/v1/workspaces/{$ws->id}/search?query="), 422, 'invalid_request');
    expect(Iso::now())->not->toBeNull();
});
