# TextDistiller

Deterministic text compression engine for knowledge graph observations. Extracts atomic facts from narrative text without an LLM — no hallucination, no latency, no network I/O.

Uses **TF-IDF + LexRank** (proven extractive summarization algorithms) for domain-agnostic scoring. Automatically adapts to any domain — systems administration, law, medicine, game development — because importance is defined by the corpus itself, not hardcoded patterns.

## Usage

```bash
# Pipe text in
echo "FreeBSD 15.0 deployed on nebula with ArcadeDB 26.5.1" | ./bin/distill

# Pass as argument
./bin/distill "Some observation text here"

# Read from file
./bin/distill --file observations.txt

# Background corpus (entity's existing observations, for TF-IDF context)
./bin/distill --file new_obs.txt --corpus existing_observations.txt

# JSON output (for programmatic use)
./bin/distill --json < input.txt

# Verbose (show N/C/E scoring breakdown)
./bin/distill --verbose < input.txt
```

## Actions

| Action | Meaning | Output |
|--------|---------|--------|
| `archive` | Structured/reference content (config, code, tables) | Keep verbatim |
| `prune` | Session narrative, no durable facts | Discard |
| `compress` | Factual content extracted | List of atomic facts |

## Scoring Pipeline

Each sentence receives a fused score from three signals:

| Signal | Weight | Description |
|--------|--------|-------------|
| **TF-IDF Novelty** | 40% | Terms rare across the corpus score higher (informationally novel) |
| **LexRank Centrality** | 30% | Sentences central to the observation's meaning (eigenvector centrality) |
| **Entity Signal** | 30% | Density of concrete identifiers (names, numbers, paths, code) |

The background corpus makes TF-IDF truly domain-adaptive: pass an entity's existing observations and terms already well-represented score lower, surfacing only genuinely new information.

## Architecture

```
src/
├── Classifier.class.php        — Rule-based action classification (archive/prune/extract)
├── SentenceSegmenter.class.php — Unicode-aware segmentation (IntlBreakIterator)
├── TfIdf.class.php             — TF-IDF vectorization + novelty scoring
├── LexRank.class.php           — Graph-based sentence centrality (power iteration)
├── EntitySignal.class.php      — Lightweight entity/identifier density detector
├── Extractor.class.php         — Fused scoring pipeline orchestrator
└── Distiller.class.php         — Facade (consumer-facing API)
```

### Design Principles

1. **Extract, don't generate** — Output is always a substring of input (possibly trimmed). Never paraphrased. No hallucination by construction.
2. **Domain-agnostic** — TF-IDF lets the corpus define what's "important". No hardcoded keyword lists.
3. **Fused multi-signal scoring** — Per arxiv 2607.25335, best results come from combining multiple linguistic levels.
4. **Zero external dependencies** — Pure PHP using only bundled extensions (intl, mbstring, pcre).
5. **Fail safe** — When no high-scoring facts are found, falls back to `archive` rather than discarding.

### Performance

- ~0.55ms per observation (typical 3-6 sentences, no corpus)
- ~1.8ms per observation with 50-entry background corpus
- Deterministic: same input always produces same output

## Requirements

- PHP 8.1+ with `ext-intl` and `ext-mbstring`

## Vendoring / Integration

The `src/` directory is designed to be vendored directly into a host application:

```bash
cp -r src/ /path/to/your-app/libraries/TextDistiller/
```

The `TextDistiller\` namespace maps to the directory structure. Any PSR-4 compatible autoloader resolves it automatically.

### Use Cases

- **Knowledge graphs** — Pre-filter observations before storage; classify and extract without LLM calls
- **Search engines** — Extract key sentences from crawled pages; rank by novelty relative to already-indexed content
- **RAG pipelines** — Score and rank retrieved chunks by informativeness before feeding to an LLM
- **Email/notification digests** — Surface novel action items from message threads

## License

BSD-3-Clause
