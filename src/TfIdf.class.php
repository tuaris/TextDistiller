<?php
/**
 * TextDistiller - TF-IDF Scorer
 *
 * Computes Term Frequency × Inverse Document Frequency vectors for sentences.
 * When a background corpus is provided, IDF reflects corpus-wide term rarity —
 * words rare across the entity's existing observations score higher (novel info).
 * Without a corpus, falls back to document-internal IDF (still better than regex).
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller;

use TextDistiller\Language\LanguageProvider;
use TextDistiller\Language\English;

class TfIdf
{
    private LanguageProvider $language;

    /** @var array<string, true> Lookup table for stop words. */
    private array $stopWordMap;

    public function __construct(?LanguageProvider $language = null)
    {
        $this->language = $language ?? new English();
        $this->stopWordMap = array_fill_keys($this->language->stopWords(), true);
    }

    /**
     * Compute TF-IDF vectors for a set of sentences.
     *
     * @param  string[] $sentences       The sentences to score
     * @param  string[] $backgroundCorpus Optional: existing observations for IDF context
     * @return array{vectors: array[], idf: array, noveltyScores: float[]}
     */
    public function compute(array $sentences, array $backgroundCorpus = []): array
    {
        // Tokenize all sentences
        $sentenceTerms = array_map([$this, 'tokenize'], $sentences);

        // Build the document collection: sentences + background corpus entries
        $allDocTerms = $sentenceTerms;
        foreach ($backgroundCorpus as $doc) {
            $allDocTerms[] = $this->tokenize($doc);
        }

        // Compute IDF across the full collection
        $idf = $this->computeIdf($allDocTerms);

        // Compute TF-IDF vector per sentence
        $vectors = [];
        foreach ($sentenceTerms as $terms) {
            $vectors[] = $this->computeTfIdfVector($terms, $idf);
        }

        // Novelty score: sum of TF-IDF weights (higher = more informative)
        $noveltyScores = [];
        foreach ($vectors as $vec) {
            $noveltyScores[] = array_sum($vec);
        }

        // Normalize novelty scores to [0, 1]
        $maxNovelty = max($noveltyScores) ?: 1.0;
        $noveltyScores = array_map(fn($s) => $s / $maxNovelty, $noveltyScores);

        return [
            'vectors' => $vectors,
            'idf' => $idf,
            'noveltyScores' => $noveltyScores,
        ];
    }

    /**
     * Tokenize a string into normalized terms using IntlBreakIterator.
     *
     * ICU word-breaking keeps version strings and IPs (2.4.1, 10.0.0.1)
     * as single terms, but splits on '/' and '-': paths and hyphenated
     * names become multiple terms (src/io.zig → src, io.zig).
     * Applies language-specific normalization via the LanguageProvider.
     *
     * @param  string $text
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $bi = \IntlBreakIterator::createWordInstance($this->language->locale());
        $bi->setText($text);

        $terms = [];
        $prev = 0;
        $minLen = $this->language->minTermLength();

        foreach ($bi as $pos) {
            // IntlBreakIterator returns byte offsets
            $word = trim(substr($text, $prev, $pos - $prev));
            $prev = $pos;

            if ($word === '') continue;

            // Skip pure punctuation/whitespace
            if (preg_match('/^[\p{P}\p{S}\p{Z}]+$/u', $word)) continue;

            $normalized = $this->normalizeWord($word);
            if ($normalized === '') continue;

            // Skip stop words
            if (isset($this->stopWordMap[$normalized])) continue;

            // Skip terms below minimum length (language-dependent)
            if (mb_strlen($normalized) < $minLen && !preg_match('/\d/', $normalized)) continue;

            $terms[] = $normalized;
        }

        return $terms;
    }

    /**
     * Normalize a term: lowercase + language-specific morphological normalization.
     */
    private function normalizeWord(string $word): string
    {
        $word = mb_strtolower($word);

        // Strip leading/trailing Unicode punctuation and symbols. rtrim's
        // byte-wise character list cannot handle multi-byte codepoints, so
        // a typographic apostrophe (U+2019) would survive and split the
        // vocabulary (porter’ ≠ porter).
        $word = (string) preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $word);

        // Delegate to language provider for morphological normalization.
        // Suffix stripping can expose punctuation again (porter’s → porter’),
        // so strip once more afterwards.
        $word = $this->language->normalize($word);
        return (string) preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $word);
    }

    /**
     * Compute IDF (Inverse Document Frequency) for all terms across documents.
     *
     * IDF(t) = log(N / df(t)) where:
     * - N = total number of documents
     * - df(t) = number of documents containing term t
     *
     * @param  array<string[]> $allDocTerms
     * @return array<string, float>
     */
    private function computeIdf(array $allDocTerms): array
    {
        $N = count($allDocTerms);
        if ($N === 0) return [];

        // Count document frequency for each term
        $df = [];
        foreach ($allDocTerms as $terms) {
            $unique = array_unique($terms);
            foreach ($unique as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        // Compute IDF with smoothing: log((N + 1) / (df + 1)) + 1
        // Smoothing prevents division by zero and dampens extreme values
        $idf = [];
        foreach ($df as $term => $docFreq) {
            $idf[$term] = log(($N + 1) / ($docFreq + 1)) + 1.0;
        }

        return $idf;
    }

    /**
     * Compute TF-IDF vector for a single document's terms.
     *
     * TF is raw count (not normalized by doc length) for short texts.
     *
     * @param  string[] $terms
     * @param  array<string, float> $idf
     * @return array<string, float>
     */
    private function computeTfIdfVector(array $terms, array $idf): array
    {
        if (empty($terms)) return [];

        // Count term frequencies
        $tf = array_count_values($terms);

        // Normalize TF by document length (augmented TF: 0.5 + 0.5 * f/max_f)
        $maxTf = max($tf) ?: 1;

        $vector = [];
        foreach ($tf as $term => $count) {
            $normalizedTf = 0.5 + 0.5 * ($count / $maxTf);
            $termIdf = $idf[$term] ?? 1.0;
            $vector[$term] = $normalizedTf * $termIdf;
        }

        return $vector;
    }

    /**
     * Compute cosine similarity between two TF-IDF vectors.
     *
     * @param  array<string, float> $v1
     * @param  array<string, float> $v2
     * @return float 0.0 to 1.0
     */
    public static function cosineSimilarity(array $v1, array $v2): float
    {
        if (empty($v1) || empty($v2)) return 0.0;

        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        // Only iterate over shared terms for dot product
        foreach ($v1 as $term => $weight) {
            $norm1 += $weight * $weight;
            if (isset($v2[$term])) {
                $dotProduct += $weight * $v2[$term];
            }
        }

        foreach ($v2 as $weight) {
            $norm2 += $weight * $weight;
        }

        $denominator = sqrt($norm1) * sqrt($norm2);
        if ($denominator < 1e-10) return 0.0;

        return $dotProduct / $denominator;
    }
}
