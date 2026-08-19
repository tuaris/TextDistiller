# Contributing to TextDistiller

## Adding a New Language

TextDistiller uses a pluggable `LanguageProvider` interface for multilingual support. Adding a new language requires **no changes** to the core algorithms (TF-IDF, LexRank, EntitySignal) — only a single class implementing the interface.

### Steps

1. **Create** `src/Language/YourLanguage.class.php`:

```php
<?php
namespace TextDistiller\Language;

class Spanish implements LanguageProvider
{
    public function locale(): string
    {
        return 'es';  // ICU locale code
    }

    public function stopWords(): array
    {
        return ['de', 'la', 'el', 'en', 'y', 'los', 'del', ...];
    }

    public function normalize(string $term): string
    {
        // Spanish suffix stripping (optional, return $term unchanged if unsure)
        if (str_ends_with($term, 'ción') && mb_strlen($term) > 6) {
            return mb_substr($term, 0, -4);
        }
        // ... more rules
        return $term;
    }

    public function minTermLength(): int
    {
        return 2;  // CJK languages should return 1
    }
}
```

2. **Add test cases** in `tests/lang/es_cases.php` (or your language code):
   - At least 3 sentences that should be extracted (compress action)
   - At least 1 that should be pruned or archived
   - Verify with: `php tests/lang/es_cases.php`

3. **Submit a PR** with:
   - The language class file
   - The test cases file
   - A brief note about your stop word source (e.g., "NLTK Spanish stop words" or "hand-curated")

### Interface Reference

| Method | Purpose | Notes |
|--------|---------|-------|
| `locale()` | ICU locale code | Used by `IntlBreakIterator` for sentence/word boundaries |
| `stopWords()` | Function words to exclude | 50-200 words, all lowercase |
| `normalize()` | Morphological normalization | Strip common suffixes; return unchanged if unsure |
| `minTermLength()` | Minimum term length | Return `1` for CJK (Chinese, Japanese, Korean) |

### Language-Specific Notes

#### CJK Languages (Chinese, Japanese, Korean)

- ICU handles segmentation correctly via `IntlBreakIterator` — no custom tokenizer needed
- Set `minTermLength()` to `1` (single characters are meaningful)
- Stop words should include particles/postpositions (的, は, 은/는, etc.)
- `normalize()` can return the input unchanged — CJK typically doesn't need stemming

#### Agglutinative Languages (Turkish, Finnish, Hungarian)

- Suffix stripping is particularly valuable here (deep morphology)
- Focus `normalize()` on the most common case suffixes
- A full morphological analyzer is NOT required — even partial normalization helps TF-IDF significantly

#### Right-to-Left Languages (Arabic, Hebrew)

- ICU handles RTL segmentation correctly
- Stop words should include common prefixes (ال, ب, و for Arabic)
- `normalize()` may need to strip prefix articles (Arabic definite article ال)

### What You DON'T Need to Change

- `Extractor.class.php` — automatically uses your language via the constructor
- `LexRank.class.php` — language-independent (operates on numeric vectors)
- `EntitySignal.class.php` — regex patterns are mostly Unicode-aware already
- `Classifier.class.php` — English-specific prune phrases are a separate concern (future: make these configurable too)

### Testing

```bash
# Run English regression tests (should still pass)
php tests/run_cases.php

# Run your language-specific tests
php tests/lang/your_lang_cases.php

# Quick manual test
php bin/distill "Your test text in the target language"
```

## Other Contributions

- **Bug reports**: Include the input text, expected action, and actual action
- **New EntitySignal patterns**: If you find entity-like patterns missing across multiple languages, propose additions to `EntitySignal.class.php`
- **Performance improvements**: Benchmark before/after with `php -r "..."` timing loops (see README)
- **New use cases**: If you're using TextDistiller outside Heliofane (crawlers, search engines, RAG pipelines), share your integration pattern
