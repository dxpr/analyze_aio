> **Analyze AIO** scores content readiness for AI answer engines like
> ChatGPT, Perplexity, and Gemini. An
> [Analyze](https://www.drupal.org/project/analyze) ecosystem plugin
> by [DXPR](https://dxpr.com).
>
> [Getting Started](https://dxpr.com/c/marketing-cms) |
> [Pricing](https://dxpr.com/pricing) |
> [Try Free Demo](https://try.dxpr.com)

# AI Optimization Analysis: AI Answer Engine Readiness Scoring for Drupal

Automated analysis measuring how well your content is structured for
AI answer engines (ChatGPT, Perplexity, Gemini, Google AI Overviews).

## Features

- **8 Deterministic Checks**: Heading hierarchy, JSON-LD structured data,
  meta tag completeness, semantic HTML, FAQ patterns, internal link density,
  plus optional AI checks
- **Visual Feedback**: Gauge displays with percentage scores and
  progressive-disclosure full reports grouped by severity
- **Educational Reports**: Each failing check explains why it matters for AI
  answer engines and what to do, teaching AIO alongside scoring
- **Analyze Framework Integration**: Consistent UI and reporting across all
  analysis tools
- **Batch Processing**: Analyse large content volumes efficiently via GUI or
  Drush
- **Smart Caching**: Content-hash-based cache invalidation; results are only
  recomputed when content changes

## Requirements

- [Analyze](https://www.drupal.org/project/analyze) framework (>=1.3.0)
- [Views Color Scales](https://www.drupal.org/project/views_color_scales)
  (>=1.1.0)

Optional:
- [AI](https://www.drupal.org/project/ai) module with configured provider
  (enables citation readiness and tone authority checks)

## Installation

```bash
composer require drupal/analyze_aio
drush en analyze_aio
```

## Configuration

### Basic Setup
1. Enable per content type at `/admin/config/content/analyze-settings`
2. Configure checks at `/admin/config/analyze/aio`
3. Configure permissions at
   `/admin/people/permissions#module-analyze_aio`

### Optional AI Checks
1. Configure an AI provider at `/admin/config/ai/providers`
2. Enable AI checks at `/admin/config/analyze/aio`
3. Two additional checks become available: citation readiness and
   tone authority

### Check Reference
| Check | What it scores |
|---|---|
| Heading Hierarchy | Single H1, proper nesting, question-shaped headings |
| JSON-LD | Structured data with content-type and AI-friendly schemas |
| Meta Completeness | Title, description, Open Graph, canonical URL |
| Semantic HTML | article, section, figure, time, details elements |
| FAQ Detection | FAQ schema, definition lists, question headings |
| Internal Link Density | Internal and external link coverage |
| Citation Readiness | AI: whether content is structured for citation |
| Tone Authority | AI: expertise signals and authoritative voice |

## AI Coding Assistant Integration

The AIO module includes a built-in
[Agent Skills](https://agentskills.io) file (via the base
Analyze module) that teaches AI coding assistants how to run
AI optimisation analysis through natural language. Run
`drush analyze:setup-ai` to enable, then ask naturally:

```
"Analyse AI optimisation readiness on all articles"
"Check structured data on the about page"
"Run AIO and SEO analysis together on blog posts"
```

Batch processing is available via the centralised Analyze
batch system:

```bash
# Check analysis coverage
drush analyze:batch --status

# Run this analyzer on all enabled content types
drush analyze:batch \
  --analyzers=analyze_aio_readiness

# Run on specific content types with limit
drush analyze:batch \
  --analyzers=analyze_aio_readiness \
  --types=node:article --limit=50

# Force re-analysis of already analysed content
drush analyze:batch \
  --analyzers=analyze_aio_readiness --force
```

Compatible with Claude Code, Codex CLI, Gemini CLI, GitHub
Copilot, Cursor, and other tools supporting the
[Agent Skills standard](https://agentskills.io/specification).

## Analysis

Results show AI optimisation scores with visual gauge progression and
progressive-disclosure full reports. Cache invalidation is automatic;
content is only re-analysed when it changes.

### Display Options
- Gauge visualisation with clear progression
- Full report with severity-grouped checks (Needs attention / Can be
  improved / Passed)
- Views integration for custom reports
- Colour-coded results with Views Color Scales module

## Development

### Docker Commands
```bash
# Lint code
docker compose run --rm drupal-lint

# Check deprecations
docker compose run --rm drupal-check

# Auto-fix issues
docker compose run --rm drupal-lint-auto-fix
```

### Pre-commit Hook
Automatically runs linting checks before commits:
- Auto-fixes coding standard violations
- Blocks commits with remaining issues
- Provides coloured output for feedback

## Related Modules

Required dependencies; this module directly uses their APIs:

- [Analyze](https://www.drupal.org/project/analyze) - Content analysis
  framework; this module extends AnalyzePluginBase and implements
  BatchableAnalyzerInterface
- [Views Color Scales](https://www.drupal.org/project/views_color_scales) -
  Colour-coded score display in the bundled AIO Views report

Optional integration:

- [AI](https://www.drupal.org/project/ai) - LLM provider layer; enables
  citation readiness and tone authority checks

Sibling Analyze plugins:

- [SEO Analysis](https://www.drupal.org/project/analyze_seo) -
  Traditional SEO scoring
- [AI Brand Voice](https://www.drupal.org/project/analyze_ai_brand_voice) -
  Brand voice consistency scoring
- [AI Sentiments Analysis](https://www.drupal.org/project/analyze_ai_sentiments) -
  Multi-dimensional content tone analysis
- [Broken Links Analyzer](https://www.drupal.org/project/analyze_broken_links) -
  Link health monitoring per page
- [Search Console Analyzer](https://www.drupal.org/project/analyze_search_console) -
  Google Search performance per page
