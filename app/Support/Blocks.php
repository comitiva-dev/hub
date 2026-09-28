<?php

namespace App\Support;

use stdClass;

/**
 * The contract's message blocks (ADR 0004 in comitiva), as decoded JSON
 * (stdClass). The rules here mirror the desktop's, so a reply built from
 * streamed events equals the one the desktop built.
 */
final class Blocks
{
    public const MAX_TITLE = 60;

    /**
     * Appends streamed text: extends the last block when it is text, else
     * starts a new text block (contract `appendText`).
     *
     * @param  list<stdClass>  $content
     * @return list<stdClass>
     */
    public static function appendText(array $content, string $text): array
    {
        $last = end($content);
        if ($last instanceof stdClass && ($last->type ?? null) === 'text') {
            $content[array_key_last($content)] = (object) ['type' => 'text', 'text' => $last->text.$text];

            return $content;
        }
        $content[] = (object) ['type' => 'text', 'text' => $text];

        return $content;
    }

    /**
     * The title a conversation gets from its first message: the first
     * non-blank line, whitespace collapsed, at most 60 characters (the
     * desktop's `placeholderTitle`).
     *
     * @param  list<stdClass>  $content
     */
    public static function placeholderTitle(array $content): ?string
    {
        foreach (explode("\n", self::text($content)) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            if (mb_strlen($line) <= self::MAX_TITLE) {
                return $line;
            }

            return rtrim(mb_substr($line, 0, self::MAX_TITLE - 1)).'…';
        }

        return null;
    }

    /**
     * What search indexes: text blocks and attachment names, as the desktop's
     * FTS table does (tool calls are left out).
     *
     * @param  list<stdClass>  $content
     */
    public static function searchText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            $type = $block->type ?? null;
            if ($type === 'text') {
                $parts[] = $block->text;
            } elseif ($type === 'document' || ($type === 'image' && isset($block->name))) {
                $parts[] = $block->name;
            }
        }

        return implode("\n", $parts);
    }

    /** @param  list<stdClass>  $content */
    public static function text(array $content): string
    {
        $texts = [];
        foreach ($content as $block) {
            if (($block->type ?? null) === 'text') {
                $texts[] = $block->text;
            }
        }

        return implode("\n", $texts);
    }

    /**
     * A user message is not blank (a refinement JSON Schema cannot carry).
     *
     * @param  list<stdClass>  $content
     */
    public static function hasContent(array $content): bool
    {
        foreach ($content as $block) {
            if (($block->type ?? null) !== 'text' || trim($block->text) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Ids of the attachments a message refers to (file sources hold the hub's attachment id).
     *
     * @param  list<stdClass>  $content
     * @return list<string>
     */
    public static function attachmentIds(array $content): array
    {
        $ids = [];
        foreach ($content as $block) {
            if (in_array($block->type ?? null, ['image', 'document'], true) && ($block->source->kind ?? null) === 'file') {
                $ids[] = $block->source->path;
            }
        }

        return $ids;
    }
}
