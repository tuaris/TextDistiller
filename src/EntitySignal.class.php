<?php
/**
 * TextDistiller - Entity Signal Detector
 *
 * Lightweight "entity density" scorer that detects factual anchors
 * in a sentence: proper nouns, version numbers, file paths, IPs,
 * hostnames, inline code, and other concrete identifiers.
 *
 * This is the domain-agnostic successor to the original KEYWORD_PATTERNS
 * regex scorer. Instead of hardcoded DevOps patterns, it detects
 * universal "entity-like" tokens that any knowledge graph would want.
 *
 * Returns a normalized 0.0-1.0 signal per sentence.
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller;

class EntitySignal
{
    /**
     * Patterns that detect "entity-like" tokens across any domain.
     * Each hit counts as one entity signal point.
     */
    private const ENTITY_PATTERNS = [
        // Universal identifiers
        'number'     => '/\b\d+(\.\d+)+\b/',                              // Versioned numbers (1.2.3, 8.4.24)
        'path'       => '/(?:\/[\w._-]+){2,}/',                            // File/URL paths (/usr/local/bin/foo)
        'url'        => '/https?:\/\/[\w.\/-]+/',                          // URLs
        'email'      => '/\b[\w.+-]+@[\w.-]+\.\w{2,}\b/',                 // Email addresses
        'ip'         => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',      // IP addresses
        'code'       => '/`[^`]{2,}`/',                                    // Inline code backticks
        'quoted'     => '/"[^"]{2,}"/',                                    // Quoted specific values

        // Name-like tokens (domain-agnostic)
        'proper'     => '/\b[A-Z][a-z]+(?:\s[A-Z][a-z]+)+\b/',           // Multi-word proper names
        'camel'      => '/\b[A-Z][a-z]+[A-Z][A-Za-z]*\b/',               // CamelCase identifiers
        'acronym'    => '/\b[A-Z]{2,6}\b/',                               // Acronyms (2-6 chars)
        'identifier' => '/\b[a-z][\w]*_[\w]+\b/',                         // snake_case identifiers

        // Assignment/definition patterns (universal factual anchors)
        'assignment' => '/\b[\w.]+\s*[:=→]\s*\S+/',                       // key=value, key: value, key→value
        'arrow'      => '/\b\S+\s*->\s*\S+/',                             // transitions (old->new)
    ];

    /**
     * Compute entity signal score for a sentence.
     *
     * @param  string $sentence
     * @return float  0.0 to 1.0 (normalized density of entity-like tokens)
     */
    public function score(string $sentence): float
    {
        $hits = 0;

        foreach (self::ENTITY_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $sentence, $matches)) {
                $hits += count($matches[0]);
            }
        }

        // Normalize: diminishing returns above ~5 entities per sentence
        // Using tanh-like curve: score = 1 - 1/(1 + hits/3)
        // This gives: 0 hits = 0.0, 1 hit = 0.25, 3 hits = 0.50, 6 hits = 0.67, 12 hits = 0.80
        return 1.0 - (1.0 / (1.0 + $hits / 3.0));
    }

    /**
     * Score multiple sentences and return normalized scores.
     *
     * @param  string[] $sentences
     * @return float[]  Scores indexed by sentence position, 0.0 to 1.0
     */
    public function scoreAll(array $sentences): array
    {
        $scores = array_map([$this, 'score'], $sentences);

        // Re-normalize to [0, 1] range relative to the batch
        $max = max($scores) ?: 1.0;
        if ($max > 0) {
            $scores = array_map(fn($s) => $s / $max, $scores);
        }

        return $scores;
    }
}
