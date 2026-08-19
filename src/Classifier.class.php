<?php
/**
 * TextDistiller - Deterministic Observation Classifier
 *
 * Classifies text observations into action categories without an LLM:
 * - archive: structured reference payloads (config, JSON, YAML, tables, code)
 * - prune: session narrative with no durable factual content
 * - extract: contains factual content that should be distilled into atomic facts
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller;

class Classifier
{
    /** Minimum ratio of "structured" lines to trigger archive classification. */
    private const STRUCTURED_LINE_RATIO = 0.40;

    /** Patterns that indicate structured/reference content (archive). */
    private const STRUCTURED_PATTERNS = [
        '/^\s*[\{\[]/',                     // JSON/YAML opening braces
        '/^\s*[a-z_][\w]*\s*[=:]\s*/i',    // key=value or key: value
        '/^\s*\|.*\|/',                     // Markdown table rows
        '/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s/i', // SQL
        '/^\s*```/',                        // Code fence
        '/^\s*(def |class |function |fn |pub fn |const |var |let )/', // Code
        '/^\s*#\s*(include|define|ifdef|ifndef|pragma)/', // C preprocessor
        '/^\s*\$\s*\w+/',                   // Shell variable assignment
        '/^\s*(import|from|require|use)\s/', // Import statements
        '/^\s*<\/?[a-z][\w-]*[\s>]/i',      // HTML/XML tags
    ];

    /** Pattern for numbered step sequences (procedural reference, should archive). */
    private const NUMBERED_STEPS_PATTERN = '/\(\d+\)\s.*\(\d+\)\s.*\(\d+\)/s';

    /** Phrases that indicate pure session narrative (prune). */
    private const PRUNE_PHRASES = [
        'SESSION COMPLETE',
        'WORTH REMEMBERING',
        'After N turns',
        'After several turns',
        'I then discovered',
        'I then realized',
        'This was a productive session',
        'This session covered',
        'Summary of today',
        'Today we worked on',
        'will continue in the next session',
        'RESUME PROMPT',
        'kept relearning this',
        'that cost',
        'THAT COST',
    ];

    /** Prune patterns (regex). */
    private const PRUNE_PATTERNS = [
        '/^(today|this session|in this session|during this session)\b/i',
        '/^(we|I) (worked on|discussed|covered|explored|investigated)\b/i',
        '/^(next session|follow.?up|TODO for next)\b/i',
        '/\b(session \d+|session complete|wrap.?up)\b/i',
    ];

    /**
     * Classify an observation into an action category.
     *
     * @param  string $content The observation text
     * @return string One of: 'archive', 'prune', 'extract'
     */
    public function classify(string $content): string
    {
        $content = trim($content);

        if ($this->isStructured($content)) {
            return 'archive';
        }

        if ($this->isNarrative($content)) {
            return 'prune';
        }

        return 'extract';
    }

    /**
     * Detect structured/reference content that should be archived verbatim.
     */
    private function isStructured(string $content): bool
    {
        // Numbered step sequences are procedural references (keep whole)
        if (preg_match(self::NUMBERED_STEPS_PATTERN, $content)) {
            return true;
        }

        $lines = explode("\n", $content);
        if (count($lines) < 3) {
            return false;
        }

        $structuredCount = 0;
        foreach ($lines as $line) {
            foreach (self::STRUCTURED_PATTERNS as $pattern) {
                if (preg_match($pattern, $line)) {
                    $structuredCount++;
                    break;
                }
            }
        }

        return ($structuredCount / count($lines)) >= self::STRUCTURED_LINE_RATIO;
    }

    /** Patterns indicating the text contains technical substance (overrides prune). */
    private const TECHNICAL_OVERRIDE_PATTERNS = [
        '/\b\d+\.\d+[\.\d]*\b/',                   // Version numbers
        '/(?:\/[\w._-]+){2,}/',                     // File paths (2+ segments)
        '/\b[a-z][\w-]*\.\w{2,}\b/i',              // Domain names / filenames
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', // IP addresses
        '/\b[A-Z][a-z]+[A-Z]+[A-Za-z]*\b/',        // Mixed tech names (FreeBSD, OpenARC)
        '/\b\d+\s*->\s*\d+\b/',                    // Numeric transitions (1237->438)
        '/`[^`]{3,}`/',                             // Inline code
        '/\b[a-z][\w]*-[a-z][\w-]*\b/',            // Hyphenated identifiers (auth-core, curl-multi)
        '/\b[A-Z][A-Z0-9]{1,5}\b/',                // Short acronyms/model names (P40, SSH, TLS)
    ];

    /** Minimum technical token count to override a prune decision. */
    private const TECHNICAL_OVERRIDE_THRESHOLD = 3;

    /**
     * Detect session narrative that should be pruned.
     */
    private function isNarrative(string $content): bool
    {
        $hasPruneSignal = false;

        foreach (self::PRUNE_PHRASES as $phrase) {
            if (mb_stripos($content, $phrase) !== false) {
                $hasPruneSignal = true;
                break;
            }
        }

        if (!$hasPruneSignal) {
            foreach (self::PRUNE_PATTERNS as $pattern) {
                if (preg_match($pattern, $content)) {
                    $hasPruneSignal = true;
                    break;
                }
            }
        }

        if ($hasPruneSignal) {
            // Override: if the text contains enough technical substance,
            // it's a factual observation that happens to use narrative phrasing
            $techCount = $this->countTechnicalTokens($content);
            if ($techCount >= self::TECHNICAL_OVERRIDE_THRESHOLD) {
                return false;
            }
            return true;
        }

        // Heuristic: very short text with no proper nouns, numbers, or paths
        if (mb_strlen($content) < 100 && !preg_match('/[A-Z][a-z]+|\/[\w\/]+|\d{2,}|:\d+/', $content)) {
            return true;
        }

        return false;
    }

    /**
     * Count technical tokens that indicate substantive content.
     */
    private function countTechnicalTokens(string $content): int
    {
        $count = 0;
        foreach (self::TECHNICAL_OVERRIDE_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $count += count($matches[0]);
            }
        }
        return $count;
    }
}
