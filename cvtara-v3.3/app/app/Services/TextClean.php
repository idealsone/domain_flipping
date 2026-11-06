<?php

namespace App\Services;

/**
 * Cleans resume text that came out of a PDF or Word file: bullet glyphs the file's font could not map ("?" or "�"
 * at the start of a line), emoji and symbol bullets (✔ ➤ ★ ■ 🏆), dash bullets, and a "?" standing in for a lost
 * apostrophe ("Amazon?s"). Every bullet becomes "• " so the importer and the writers see one bullet style.
 */
class TextClean
{
    /** Emoji, pictographs, dingbats, arrows and geometric shapes used as bullets or decoration. */
    public const SYMBOLS = '\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{25FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F000}-\x{1FAFF}\x{FE0F}\x{200D}\x{20E3}';

    public static function line(string $l): string
    {
        // A lost apostrophe: a "?" or "�" between a word and a common ending (Amazon?s, don?t, I?ve).
        $l = (string) preg_replace('/(?<=\p{L})[?\x{FFFD}](?=(?:s|t|re|ll|ve|d|m)\b)/u', '’', $l);
        // Bullet glyphs at the start of a line: unmapped "?"/"�", symbols, emoji, dashes, small bullets.
        $l = (string) preg_replace('/^\s*(?:[?\x{FFFD}\x{E000}-\x{F8FF}'.self::SYMBOLS.'•●▪◦·‣⁃∙*]+|[\-–—](?=\s))\s*/u', '• ', $l, 1, $n);
        // Any other emoji or replacement character inside the text is dropped (PDF fonts cannot print it).
        $l = (string) preg_replace('/[\x{FFFD}\x{1F000}-\x{1FAFF}\x{FE0F}\x{200D}]/u', '', $l);
        // A "?" between two items on one line ("CERTIFICATIONS ? Power BI") is a lost bullet too.
        $l = (string) preg_replace('/(?<=\S)\s+\?\s+(?=\p{Lu})/u', "\n• ", $l);

        return $l;
    }

    /** Cleans every line of a block of text; a lost mid-line bullet becomes a line of its own. */
    public static function text(string $t): string
    {
        return implode("\n", array_map([self::class, 'line'], preg_split('/\R/u', $t) ?: []));
    }
}
