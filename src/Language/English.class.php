<?php
/**
 * TextDistiller - English Language Provider
 *
 * Default language support. Provides English stop words and
 * lightweight suffix normalization (not a full Porter stemmer).
 *
 * @package    TextDistiller
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace TextDistiller\Language;

class English implements LanguageProvider
{
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'was', 'are', 'were', 'be', 'been',
        'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'shall', 'can', 'need', 'dare',
        'it', 'its', 'this', 'that', 'these', 'those', 'i', 'we', 'you', 'he',
        'she', 'they', 'me', 'us', 'him', 'her', 'them', 'my', 'our', 'your',
        'his', 'their', 'mine', 'ours', 'yours', 'hers', 'theirs',
        'what', 'which', 'who', 'whom', 'when', 'where', 'why', 'how',
        'not', 'no', 'nor', 'if', 'then', 'else', 'so', 'as', 'than',
        'too', 'very', 'just', 'also', 'only', 'still', 'already', 'even',
        'all', 'each', 'every', 'both', 'few', 'more', 'most', 'some', 'any',
        'such', 'into', 'through', 'during', 'before', 'after', 'above',
        'below', 'between', 'out', 'off', 'over', 'under', 'again', 'further',
        'about', 'up', 'down', 'here', 'there', 'once',
    ];

    public function locale(): string
    {
        return 'en';
    }

    public function stopWords(): array
    {
        return self::STOP_WORDS;
    }

    public function normalize(string $term): string
    {
        // Only apply to words > 5 chars to avoid destroying short technical terms
        if (mb_strlen($term) <= 5) {
            return $term;
        }

        // -ing
        if (str_ends_with($term, 'ing') && mb_strlen($term) > 6) {
            return mb_substr($term, 0, -3);
        }
        // -tion/-sion
        if (preg_match('/(tion|sion)$/', $term) && mb_strlen($term) > 7) {
            return mb_substr($term, 0, -4);
        }
        // -ed
        if (str_ends_with($term, 'ed') && mb_strlen($term) > 5) {
            return mb_substr($term, 0, -2);
        }
        // -ly
        if (str_ends_with($term, 'ly') && mb_strlen($term) > 5) {
            return mb_substr($term, 0, -2);
        }
        // -es
        if (str_ends_with($term, 'es') && mb_strlen($term) > 5) {
            return mb_substr($term, 0, -2);
        }
        // -s (not -ss)
        if (str_ends_with($term, 's') && !str_ends_with($term, 'ss') && mb_strlen($term) > 4) {
            return mb_substr($term, 0, -1);
        }

        return $term;
    }

    public function minTermLength(): int
    {
        return 2;
    }
}
