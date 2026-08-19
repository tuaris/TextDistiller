<?php
/**
 * TextDistiller - Main Entry Point (Facade)
 *
 * Combines Classifier + Extractor into a single distill() call.
 * This is the class consumers use directly.
 *
 * When vendored into a host application as a library, this class implements
 * the same interface as CompressionProvider but deterministically:
 * no LLM calls, no network I/O, no latency, no hallucination.
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller;

use TextDistiller\Language\LanguageProvider;

class Distiller
{
    private Classifier $classifier;
    private Extractor $extractor;

    /**
     * @param LanguageProvider|null $language Optional language provider for multilingual support.
     *                                       Defaults to English. See src/Language/ for available
     *                                       languages or implement LanguageProvider to add your own.
     */
    public function __construct(?LanguageProvider $language = null)
    {
        $this->classifier = new Classifier();
        $this->extractor = new Extractor($language);
    }

    /**
     * Distill an observation into a structured result.
     *
     * Returns the same shape as the LLM compression provider:
     * - action: 'archive' | 'prune' | 'compress'
     * - facts: extracted atomic facts (empty for archive/prune)
     * - reason: human-readable explanation of the decision
     * - metadata: extraction statistics
     *
     * @param  string   $content          The observation text
     * @param  string   $entityName       Optional entity name (for context in reason)
     * @param  string[] $backgroundCorpus Optional: entity's existing observations for TF-IDF context.
     *                                    When provided, IDF weights reflect corpus-wide term rarity —
     *                                    sentences containing terms rare across the entity's history
     *                                    score higher (they represent novel information).
     * @return array{action: string, facts: string[], reason: string, metadata: array}
     */
    public function distill(string $content, string $entityName = '', array $backgroundCorpus = []): array
    {
        $action = $this->classifier->classify($content);

        if ($action === 'archive') {
            return [
                'action' => 'archive',
                'facts' => [],
                'reason' => 'Structured/reference content detected (config, code, or tabular data)',
                'confidence' => 0.95,
                'metadata' => ['input_length' => mb_strlen($content)],
            ];
        }

        if ($action === 'prune') {
            return [
                'action' => 'prune',
                'facts' => [],
                'reason' => 'Session narrative with no durable factual content',
                'confidence' => 0.90,
                'metadata' => ['input_length' => mb_strlen($content)],
            ];
        }

        // action === 'extract'
        $result = $this->extractor->extract($content, $backgroundCorpus);

        if (empty($result['facts'])) {
            // Extractor found no high-value sentences — fall back to archive
            // rather than discarding potentially useful content
            return [
                'action' => 'archive',
                'facts' => [],
                'reason' => 'No high-scoring facts extracted; archiving to preserve content',
                'metadata' => $result['metadata'],
            ];
        }

        // Compute confidence: spread between top score and threshold indicates certainty
        $scores = $result['metadata']['scores'];
        $topScore = !empty($scores) ? max($scores) : 0;
        $confidence = min(1.0, $topScore / 0.60); // Normalized: 0.60+ fused = full confidence

        return [
            'action' => 'compress',
            'facts' => $result['facts'],
            'reason' => sprintf('Extracted %d fact(s) from %d sentences (scores: %s)',
                count($result['facts']),
                $result['metadata']['sentences_found'],
                implode(', ', $result['metadata']['scores'])
            ),
            'confidence' => round($confidence, 3),
            'metadata' => $result['metadata'],
        ];
    }
}
