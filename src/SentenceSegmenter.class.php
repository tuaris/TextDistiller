<?php
/**
 * TextDistiller - Unicode-aware Sentence Segmenter
 *
 * Uses IntlBreakIterator for proper sentence boundary detection
 * (handles "Dr. Smith", "U.S.A.", abbreviations, CJK) combined
 * with newline/bullet-point awareness for structured observations.
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller;

class SentenceSegmenter
{
    /** Maximum length for a single sentence segment. */
    private const MAX_SEGMENT_LENGTH = 300;

    /** Minimum length to consider a segment meaningful. */
    private const MIN_SEGMENT_LENGTH = 8;

    private string $locale;

    public function __construct(string $locale = 'en')
    {
        $this->locale = $locale;
    }

    /**
     * Segment text into sentence-like units.
     *
     * Strategy: first split on newlines (observations are typically line-structured),
     * then apply IntlBreakIterator within each line for proper sentence splitting.
     *
     * @param  string $text
     * @return string[]
     */
    public function segment(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);
        $sentences = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Strip leading bullet/list markers
            $line = preg_replace('/^\s*[-*•]\s+/', '', $line);
            $line = preg_replace('/^\s*\d+[.)]\s+/', '', $line);
            $line = trim($line);

            if ($line === '') continue;

            // Short lines: keep as-is (single fact or fragment)
            if (mb_strlen($line) <= self::MAX_SEGMENT_LENGTH) {
                // Still try ICU splitting for compound sentences on one line
                $sub = $this->icuSplit($line);
                foreach ($sub as $s) {
                    if (mb_strlen($s) >= self::MIN_SEGMENT_LENGTH) {
                        $sentences[] = $s;
                    }
                }
                continue;
            }

            // Long lines: ICU split into proper sentences
            $sub = $this->icuSplit($line);
            foreach ($sub as $s) {
                if (mb_strlen($s) >= self::MIN_SEGMENT_LENGTH) {
                    $sentences[] = $s;
                }
            }
        }

        return $sentences;
    }

    /**
     * Split a line into sentences using IntlBreakIterator.
     *
     * Falls back to regex if ICU produces no useful splits.
     *
     * NOTE: IntlBreakIterator returns BYTE offsets (not character offsets),
     * so we must use substr() not mb_substr() for extraction.
     *
     * @param  string $text
     * @return string[]
     */
    private function icuSplit(string $text): array
    {
        $bi = \IntlBreakIterator::createSentenceInstance($this->locale);
        $bi->setText($text);

        $sentences = [];
        $prev = 0;

        foreach ($bi as $pos) {
            // IntlBreakIterator positions are byte offsets — use substr, not mb_substr
            $segment = trim(substr($text, $prev, $pos - $prev));
            if ($segment !== '') {
                $sentences[] = $segment;
            }
            $prev = $pos;
        }

        // If ICU didn't split (common for short technical lines), return whole
        if (empty($sentences)) {
            return [$text];
        }

        return $sentences;
    }
}
