<?php
/**
 * TextDistiller - LexRank Sentence Centrality
 *
 * Implements the LexRank algorithm (Erkan & Radev, 2004) for graph-based
 * sentence importance scoring. Builds a cosine-similarity graph between
 * sentences, then computes eigenvector centrality via power iteration.
 *
 * Sentences that are most "central" (similar to many other sentences)
 * rank highest — they represent the core facts of the observation.
 *
 * This is the continuous variant (weighted edges) with a damping factor,
 * equivalent to PageRank on the sentence similarity graph.
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller;

class LexRank
{
    /** Minimum cosine similarity to form an edge between sentences. */
    private const SIMILARITY_THRESHOLD = 0.10;

    /** Damping factor (same as PageRank's α = 0.85). */
    private const DAMPING_FACTOR = 0.85;

    /** Power iteration convergence threshold. */
    private const CONVERGENCE_EPSILON = 1e-6;

    /** Maximum iterations to prevent infinite loops. */
    private const MAX_ITERATIONS = 100;

    /**
     * Compute LexRank centrality scores for sentences.
     *
     * @param  array<array<string, float>> $tfidfVectors  TF-IDF vectors per sentence
     * @return float[] Centrality scores (0.0 to 1.0), indexed by sentence position
     */
    public function rank(array $tfidfVectors): array
    {
        $n = count($tfidfVectors);

        // Trivial cases
        if ($n === 0) return [];
        if ($n === 1) return [1.0];

        // Build cosine similarity matrix
        $similarity = $this->buildSimilarityMatrix($tfidfVectors);

        // Build row-stochastic transition matrix with threshold
        $transition = $this->buildTransitionMatrix($similarity, $n);

        // Power iteration to find stationary distribution
        $scores = $this->powerIteration($transition, $n);

        // Normalize to [0, 1]
        $maxScore = max($scores) ?: 1.0;
        return array_map(fn($s) => $s / $maxScore, $scores);
    }

    /**
     * Build NxN cosine similarity matrix.
     */
    private function buildSimilarityMatrix(array $vectors): array
    {
        $n = count($vectors);
        $matrix = array_fill(0, $n, array_fill(0, $n, 0.0));

        for ($i = 0; $i < $n; $i++) {
            $matrix[$i][$i] = 1.0; // Self-similarity
            for ($j = $i + 1; $j < $n; $j++) {
                $sim = TfIdf::cosineSimilarity($vectors[$i], $vectors[$j]);
                $matrix[$i][$j] = $sim;
                $matrix[$j][$i] = $sim;
            }
        }

        return $matrix;
    }

    /**
     * Build row-stochastic transition matrix with damping.
     *
     * Applies threshold: edges below SIMILARITY_THRESHOLD are zeroed.
     * Then normalizes rows to sum to 1. Applies damping factor for convergence.
     */
    private function buildTransitionMatrix(array $similarity, int $n): array
    {
        $matrix = array_fill(0, $n, array_fill(0, $n, 0.0));

        for ($i = 0; $i < $n; $i++) {
            $rowSum = 0.0;

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue; // No self-loops
                if ($similarity[$i][$j] >= self::SIMILARITY_THRESHOLD) {
                    $matrix[$i][$j] = $similarity[$i][$j];
                    $rowSum += $similarity[$i][$j];
                }
            }

            // Normalize row (make stochastic)
            if ($rowSum > 0) {
                for ($j = 0; $j < $n; $j++) {
                    $matrix[$i][$j] /= $rowSum;
                }
            } else {
                // Dangling node: uniform distribution
                $uniform = 1.0 / $n;
                for ($j = 0; $j < $n; $j++) {
                    $matrix[$i][$j] = $uniform;
                }
            }

            // Apply damping: P' = d*P + (1-d)/n
            $teleport = (1.0 - self::DAMPING_FACTOR) / $n;
            for ($j = 0; $j < $n; $j++) {
                $matrix[$i][$j] = self::DAMPING_FACTOR * $matrix[$i][$j] + $teleport;
            }
        }

        return $matrix;
    }

    /**
     * Power iteration to find the stationary distribution of the Markov chain.
     *
     * @param  array $matrix Row-stochastic transition matrix
     * @param  int   $n      Number of sentences
     * @return float[] Stationary distribution (eigenvector)
     */
    private function powerIteration(array $matrix, int $n): float|array
    {
        // Start with uniform distribution
        $v = array_fill(0, $n, 1.0 / $n);

        for ($iter = 0; $iter < self::MAX_ITERATIONS; $iter++) {
            $vNew = array_fill(0, $n, 0.0);

            // v_new = v * M  (left eigenvector: row vector × column of M)
            for ($j = 0; $j < $n; $j++) {
                for ($i = 0; $i < $n; $i++) {
                    $vNew[$j] += $v[$i] * $matrix[$i][$j];
                }
            }

            // Check convergence (L1 norm of difference)
            $diff = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $diff += abs($vNew[$i] - $v[$i]);
            }

            $v = $vNew;

            if ($diff < self::CONVERGENCE_EPSILON) {
                break;
            }
        }

        return $v;
    }
}
