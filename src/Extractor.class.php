<?php
/**
 * TextDistiller - Deterministic Fact Extractor
 *
 * Extracts atomic facts from narrative text without an LLM.
 * Uses TF-IDF novelty scoring, LexRank centrality, and entity
 * signal detection to identify and rank important sentences.
 *
 * Design principle: EXTRACT, don't generate. Output is always
 * a substring (possibly trimmed) of the input — never paraphrased.
 * This guarantees no hallucination by construction.
 *
 * Scoring pipeline (fused, per arxiv 2607.25335 best practice):
 *   Final = w_novelty * TF-IDF + w_centrality * LexRank + w_entity * EntitySignal
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller;

use TextDistiller\Language\LanguageProvider;
use TextDistiller\Language\English;

class Extractor
{
    /** Maximum length for an extracted fact. */
    private const MAX_FACT_LENGTH = 250;

    /** Minimum fused score to keep a sentence (0.0-1.0 range). */
    private const MIN_FUSED_SCORE = 0.20;

    /** Fusion weights: novelty, centrality, entity signal. */
    private const W_NOVELTY    = 0.40;
    private const W_CENTRALITY = 0.30;
    private const W_ENTITY     = 0.30;

    /** Connective prefixes to strip from extracted sentences. */
    private const STRIP_PREFIXES = [
        '/^(Therefore|Thus|Hence|So|However|Moreover|Furthermore|Additionally|Also|Then|After this|After that|Next|Finally|Basically|Essentially|In summary|To summarize|Note that|Note:)\s*[,:]?\s*/i',
        '/^(I then|I also|I noticed|I found|I discovered|We then|We also)\s*/i',
        '/^(This means|This is because|The reason is|As a result)\s*[,:]?\s*/i',
    ];

    /** Stop sentences: lines that are purely structural/navigational. */
    private const STOP_PATTERNS = [
        '/^see (also|document|archive|above|below)/i',
        '/^\[?\d+\s*(archive|document)s?\s*available\]?$/i',
        '/^(---+|===+|\*\*\*+)$/',
        '/^#{1,4}\s/',
    ];

    private SentenceSegmenter $segmenter;
    private TfIdf $tfidf;
    private LexRank $lexrank;
    private EntitySignal $entitySignal;

    public function __construct(?LanguageProvider $language = null)
    {
        $language = $language ?? new English();
        $this->segmenter = new SentenceSegmenter($language->locale());
        $this->tfidf = new TfIdf($language);
        $this->lexrank = new LexRank();
        $this->entitySignal = new EntitySignal();
    }

    /**
     * Extract atomic facts from a text observation.
     *
     * @param  string   $content          The observation text
     * @param  string[] $backgroundCorpus Optional: existing observations for IDF context
     * @return array{facts: string[], metadata: array}
     */
    public function extract(string $content, array $backgroundCorpus = []): array
    {
        $sentences = $this->segmenter->segment($content);

        if (empty($sentences)) {
            return [
                'facts' => [],
                'metadata' => [
                    'input_length' => mb_strlen($content),
                    'sentences_found' => 0,
                    'sentences_kept' => 0,
                    'scores' => [],
                ],
            ];
        }

        // Compute the three scoring signals
        $tfidfResult = $this->tfidf->compute($sentences, $backgroundCorpus);
        $noveltyScores = $tfidfResult['noveltyScores'];
        $centralityScores = $this->lexrank->rank($tfidfResult['vectors']);
        $entityScores = $this->entitySignal->scoreAll($sentences);

        // Fuse scores
        $fusedScores = [];
        for ($i = 0; $i < count($sentences); $i++) {
            $fusedScores[$i] = self::W_NOVELTY * $noveltyScores[$i]
                             + self::W_CENTRALITY * $centralityScores[$i]
                             + self::W_ENTITY * $entityScores[$i];
        }

        // Build scored list, filter stop patterns and low scores
        $scored = [];
        for ($i = 0; $i < count($sentences); $i++) {
            $text = $sentences[$i];

            // Skip stop patterns
            $isStop = false;
            foreach (self::STOP_PATTERNS as $pattern) {
                if (preg_match($pattern, $text)) {
                    $isStop = true;
                    break;
                }
            }
            if ($isStop) continue;

            if ($fusedScores[$i] >= self::MIN_FUSED_SCORE) {
                $scored[] = [
                    'text' => $text,
                    'score' => round($fusedScores[$i], 4),
                    'novelty' => round($noveltyScores[$i], 4),
                    'centrality' => round($centralityScores[$i], 4),
                    'entity' => round($entityScores[$i], 4),
                ];
            }
        }

        // Sort by fused score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Trim connective prefixes and enforce length
        $trimmed = $this->trim($scored);

        return [
            'facts' => array_values(array_column($trimmed, 'text')),
            'metadata' => [
                'input_length' => mb_strlen($content),
                'sentences_found' => count($sentences),
                'sentences_kept' => count($trimmed),
                'scores' => array_column($trimmed, 'score'),
                'scoring_detail' => array_map(fn($s) => [
                    'novelty' => $s['novelty'],
                    'centrality' => $s['centrality'],
                    'entity' => $s['entity'],
                    'fused' => $s['score'],
                ], $trimmed),
            ],
        ];
    }

    /**
     * Trim connective prefixes and enforce length limit.
     */
    private function trim(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $text = $item['text'];

            // Strip connective prefixes
            foreach (self::STRIP_PREFIXES as $pattern) {
                $text = preg_replace($pattern, '', $text);
            }

            $text = trim($text);

            // Truncate at max length (at word boundary)
            if (mb_strlen($text) > self::MAX_FACT_LENGTH) {
                $text = mb_substr($text, 0, self::MAX_FACT_LENGTH);
                $lastSpace = mb_strrpos($text, ' ');
                if ($lastSpace > self::MAX_FACT_LENGTH * 0.7) {
                    $text = mb_substr($text, 0, $lastSpace);
                }
            }

            if (mb_strlen($text) >= 10) {
                $result[] = ['text' => $text, 'score' => $item['score'],
                             'novelty' => $item['novelty'], 'centrality' => $item['centrality'],
                             'entity' => $item['entity']];
            }
        }

        return $result;
    }
}
