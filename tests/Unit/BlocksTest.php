<?php

use App\Http\Controllers\Api\V1\SearchController;
use App\Support\Blocks;
use App\Support\UsageReports;
use Illuminate\Support\Carbon;

it('appends streamed text the way the desktop does', function () {
    $content = Blocks::appendText([], 'Hel');
    $content = Blocks::appendText($content, 'lo');
    $content[] = (object) ['type' => 'tool_use', 'id' => 't', 'toolServerId' => 's', 'name' => 'n', 'input' => new stdClass];
    $content = Blocks::appendText($content, 'After');
    expect(json_encode($content))->toBe('[{"type":"text","text":"Hello"},{"type":"tool_use","id":"t","toolServerId":"s","name":"n","input":{}},{"type":"text","text":"After"}]');
});

it('titles a conversation from the first non-blank line, clipped to 60 characters', function () {
    $text = fn (string $t) => [(object) ['type' => 'text', 'text' => $t]];
    expect(Blocks::placeholderTitle($text("\n   \n  Plan   the\tlaunch  \nmore")))->toBe('Plan the launch');
    expect(Blocks::placeholderTitle($text(str_repeat('ação ', 20))))->toBe(rtrim(mb_substr(str_repeat('ação ', 20), 0, 59)).'…');
    expect(Blocks::placeholderTitle([(object) ['type' => 'image', 'source' => new stdClass]]))->toBeNull();
});

it('indexes text and attachment names, not tool calls', function () {
    $content = [
        (object) ['type' => 'text', 'text' => 'See'],
        (object) ['type' => 'document', 'name' => 'plan.md', 'mediaType' => 'text/markdown', 'source' => new stdClass],
        (object) ['type' => 'tool_use', 'id' => 't', 'toolServerId' => 's', 'name' => 'secret_tool', 'input' => new stdClass],
    ];
    expect(Blocks::searchText($content))->toBe("See\nplan.md");
});

it('turns typed words into a safe prefix tsquery and snippets into pieces', function () {
    expect(SearchController::tsquery('revisar  configura'))->toBe('revisar & configura:*');
    expect(SearchController::tsquery("a&b | c:* 'x'"))->toBe('a & b & c & x:*');
    expect(SearchController::tsquery('!! ??'))->toBeNull();
    expect(SearchController::snippet("the \u{2}plan\u{3}  is\nready"))->toBe([
        ['text' => 'the ', 'match' => false], ['text' => 'plan', 'match' => true], ['text' => ' is ready', 'match' => false],
    ]);
});

it('lists the days a window covers on the viewer calendar', function () {
    $days = fn (string $from, string $to, int $offset) => UsageReports::days(Carbon::parse($from), Carbon::parse($to), $offset);
    expect($days('2026-09-10T03:00:00Z', '2026-09-12T03:00:00Z', -180))->toBe(['2026-09-10', '2026-09-11']);
    expect($days('2026-09-10T00:00:00Z', '2026-09-10T00:00:00Z', 0))->toBe([]);
});
