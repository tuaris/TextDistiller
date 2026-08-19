<?php
/**
 * TextDistiller - Language Provider Interface
 *
 * Implement this interface to add support for a new language.
 * Each provider supplies locale-specific sentence/word segmentation,
 * stop words, and optional term normalization (stemming).
 *
 * Contributing a new language:
 *   1. Create src/Language/{Lang}.class.php implementing this interface
 *   2. Return the ICU locale code from locale()
 *   3. Provide a stop word list (50-200 high-frequency function words)
 *   4. Optionally implement normalize() for morphological normalization
 *   5. Submit a PR with test cases in tests/lang/{lang}_cases.php
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller\Language;

interface LanguageProvider
{
    /**
     * ICU locale code for IntlBreakIterator (e.g., 'en', 'es', 'de', 'ja', 'zh').
     */
    public function locale(): string;

    /**
     * Stop words to exclude from TF-IDF term vectors.
     * High-frequency function words that carry no semantic weight.
     *
     * @return string[] Lowercase stop words
     */
    public function stopWords(): array;

    /**
     * Normalize a term for TF-IDF matching.
     *
     * Applies language-specific morphological normalization:
     * stemming, lemmatization, or simple suffix stripping.
     * Return the input unchanged if no normalization applies.
     *
     * @param  string $term Lowercase term
     * @return string Normalized form
     */
    public function normalize(string $term): string;

    /**
     * Minimum term length to include in vectors (after normalization).
     * CJK languages should return 1; European languages typically 2.
     */
    public function minTermLength(): int;
}
