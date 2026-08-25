<?php

declare(strict_types=1);

namespace Drupal\analyze_aio\Plugin\Analyze;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\Service\PromptJsonDecoder\PromptJsonDecoderInterface;
use Drupal\analyze\AnalyzePluginBase;
use Drupal\analyze\BatchableAnalyzerInterface;
use Drupal\analyze\HelperInterface;
use Drupal\analyze_aio\Service\AioStorageService;
use Drupal\analyze_aio\Service\ContentInspectorService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI optimisation readiness analyser for content entities.
 *
 * @Analyze(
 *   id = "analyze_aio_readiness",
 *   label = @Translation("AI Optimization"),
 *   description = @Translation("Checks content readiness for AI answer engines: headings, structured data, meta tags, semantic HTML, and citation quality.")
 * )
 */
final class AioReadinessAnalyzer extends AnalyzePluginBase implements BatchableAnalyzerInterface {

  /**
   * Human-readable labels for each check.
   *
   * @var array<string, string>
   */
  private const CHECK_LABELS = [
    'heading_hierarchy' => 'Heading Hierarchy',
    'json_ld' => 'Structured Data (JSON-LD)',
    'meta_completeness' => 'Meta Tag Completeness',
    'semantic_html' => 'Semantic HTML',
    'faq_detection' => 'FAQ Content Patterns',
    'internal_link_density' => 'Link Density',
    'citation_readiness' => 'Citation Readiness (AI)',
    'tone_authority' => 'Tone & Authority (AI)',
  ];

  /**
   * Educational text explaining why each check matters for AI.
   *
   * @var array<string, string>
   */
  private const CHECK_WHY = [
    'heading_hierarchy' => 'AI answer engines parse headings to identify topics and subtopics. Question-shaped headings (starting with "How", "What", "Why") match the queries users ask chatbots, making your content more likely to be selected as a source.',
    'json_ld' => 'AI crawlers use structured data to classify content type and extract key facts. FAQPage and HowTo schemas are directly parseable into answer-engine response formats.',
    'meta_completeness' => 'When AI systems cite a source, they pull attribution from meta tags. Missing titles or descriptions mean your content is cited without proper context, reducing click-through.',
    'semantic_html' => 'AI parsers use semantic HTML to distinguish main content from navigation, sidebars, and boilerplate. Without semantic elements, the parser may extract irrelevant text.',
    'faq_detection' => 'FAQ patterns (question headings, definition lists, collapsible details) are the most directly extractable format for AI answer engines. A single well-structured FAQ can generate dozens of AI citations.',
    'internal_link_density' => 'AI retrieval systems evaluate topical authority partly through link structure. Internal links signal depth of coverage; external citations to authoritative sources signal credibility.',
    'citation_readiness' => 'AI systems prefer self-contained factual sentences with named sources and dates. These "cite-able atoms" can be extracted and quoted directly without needing surrounding context.',
    'tone_authority' => 'AI answer engines favour neutral, encyclopaedic content over promotional or hyperbolic text. Specific data points and comprehensive coverage increase the likelihood of being selected as a primary source.',
  ];

  /**
   * Recommendation text keyed by check ID.
   *
   * @var array<string, string>
   */
  private const RECOMMENDATIONS = [
    'heading_hierarchy' => 'Use exactly one H1, nest headings without skipping levels, and phrase subheadings as questions that AI engines can match to user queries.',
    'json_ld' => 'Add JSON-LD structured data (Article, HowTo, or FAQPage schema) so AI crawlers can classify and extract your content accurately.',
    'meta_completeness' => 'Add missing meta tags (title, description, Open Graph, canonical). These help AI systems understand and attribute your content.',
    'semantic_html' => 'Wrap content in semantic elements (article, section, figure, time) to help AI parsers identify content boundaries and context.',
    'faq_detection' => 'Add FAQ-style content using question headings, details/summary elements, or FAQPage schema. These patterns are directly extractable by AI answer engines.',
    'internal_link_density' => 'Add more internal links to related content (at least 3) and include outbound citations to authoritative sources. This builds topical authority for AI retrieval.',
    'citation_readiness' => 'Include specific, cite-able facts with source names and dates. Structure key findings as self-contained sentences that AI can extract and quote directly.',
    'tone_authority' => 'Use a neutral, encyclopaedic tone. Replace vague qualifiers with precise data and cover the topic comprehensively, anticipating follow-up questions.',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    HelperInterface $helper,
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    private readonly ContentInspectorService $contentInspector,
    private readonly AioStorageService $storage,
    private readonly ?AiProviderPluginManager $aiProvider,
    private readonly ?PromptJsonDecoderInterface $promptJsonDecoder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $helper, $currentUser, $configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $ai_provider = $container->has('ai.provider') ? $container->get('ai.provider') : NULL;
    $prompt_decoder = $container->has('ai.prompt_json_decode') ? $container->get('ai.prompt_json_decode') : NULL;

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('analyze.helper'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('analyze_aio.content_inspector'),
      $container->get('analyze_aio.storage'),
      $ai_provider,
      $prompt_decoder,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(EntityInterface $entity): bool {
    return $this->currentUser->hasPermission('access aio reports');
  }

  /**
   * {@inheritdoc}
   */
  public function renderSummary(EntityInterface $entity): array {
    $results = $this->getOrComputeResults($entity);
    if ($results === NULL) {
      return $this->createStatusTable('No content available for AI optimisation analysis.');
    }

    return [
      '#theme' => 'analyze_gauge',
      '#caption' => $this->t('AI Optimization'),
      '#range_min_label' => $this->t('Not Ready'),
      '#range_mid_label' => $this->t('Partial'),
      '#range_max_label' => $this->t('AI Ready'),
      '#range_min' => 0,
      '#range_max' => 100,
      '#value' => $results['overall_score'] / 100,
      '#display_value' => $results['overall_score'] . '%',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderFullReport(EntityInterface $entity): array {
    $results = $this->getOrComputeResults($entity);
    if ($results === NULL) {
      return $this->createStatusTable('No content available for AI optimisation analysis.');
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['analyze-full-report', 'analyze-aio-report']],
      '#attached' => ['library' => ['analyze_aio/full-report']],
    ];

    $score = $results['overall_score'];
    $check_results = $results['check_results'] ?? [];
    $grouped = $this->groupChecksByStatus($check_results);
    $pass_count = count($grouped['pass']);
    $partial_count = count($grouped['partial']);
    $fail_count = count($grouped['fail']);

    $build['score_summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['analyze-full-report__score-summary', 'gin-layer-wrapper']],
    ];
    $build['score_summary']['gauge'] = [
      '#theme' => 'analyze_circular_gauge',
      '#caption' => $this->t('AI Optimization'),
      '#range_min_label' => $this->t('Not Ready'),
      '#range_mid_label' => $this->t('Partial'),
      '#range_max_label' => $this->t('AI Ready'),
      '#range_min' => 0,
      '#range_max' => 100,
      '#value' => $score / 100,
      '#display_value' => $score . '%',
    ];
    $build['score_summary']['text'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['analyze-full-report__score-text']],
    ];
    $build['score_summary']['text']['message'] = [
      '#markup' => $this->getScoreSummaryText($score),
    ];

    $build['stats'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="analyze-full-report__stats"><span class="analyze-full-report__stat analyze-full-report__stat--pass"><span class="analyze-full-report__stat-dot"></span>{{ pass }} {{ pass_label }}</span><span class="analyze-full-report__stat analyze-full-report__stat--partial"><span class="analyze-full-report__stat-dot"></span>{{ partial }} {{ partial_label }}</span><span class="analyze-full-report__stat analyze-full-report__stat--fail"><span class="analyze-full-report__stat-dot"></span>{{ fail }} {{ fail_label }}</span></div>',
      '#context' => [
        'pass' => $pass_count,
        'pass_label' => (string) $this->t('passed'),
        'partial' => $partial_count,
        'partial_label' => (string) $this->t('to improve'),
        'fail' => $fail_count,
        'fail_label' => (string) $this->t('to fix'),
      ],
      '#weight' => 5,
    ];

    $weight = 10;
    if (!empty($grouped['fail'])) {
      $build['section_fail'] = $this->buildCheckSection(
        $this->t('Needs attention (@count)', ['@count' => count($grouped['fail'])]),
        $grouped['fail'],
        'fail',
        TRUE,
      );
      $build['section_fail']['#weight'] = $weight++;
    }

    if (!empty($grouped['partial'])) {
      $build['section_partial'] = $this->buildCheckSection(
        $this->t('Can be improved (@count)', ['@count' => count($grouped['partial'])]),
        $grouped['partial'],
        'partial',
        TRUE,
      );
      $build['section_partial']['#weight'] = $weight++;
    }

    if (!empty($grouped['pass'])) {
      $build['section_pass'] = $this->buildCheckSection(
        $this->t('Passed (@count)', ['@count' => count($grouped['pass'])]),
        $grouped['pass'],
        'pass',
        FALSE,
      );
      $build['section_pass']['#weight'] = $weight;
    }

    return $build;
  }

  /**
   * Returns a contextual summary sentence for the overall score.
   */
  private function getScoreSummaryText(int $score): string {
    if ($score >= 75) {
      return (string) $this->t('This content is well-prepared for AI answer engines. Review any remaining items below to reach full readiness.');
    }
    if ($score >= 50) {
      return (string) $this->t('This content has a partial foundation for AI discoverability. Address the items under "Needs attention" to improve how AI systems extract and cite your content.');
    }
    return (string) $this->t('This content is not yet ready for AI answer engines. The checks below identify specific changes that will make your content more discoverable and citable by AI systems like ChatGPT, Perplexity, and Gemini.');
  }

  /**
   * Groups check results into pass, partial, and fail buckets.
   *
   * @param array<string, array{score: int, max: int, details: array<string, mixed>}> $check_results
   *   The check results.
   *
   * @return array<string, array<string, array{score: int, max: int, details: array<string, mixed>}>>
   *   Grouped results keyed by 'fail', 'partial', 'pass'.
   */
  private function groupChecksByStatus(array $check_results): array {
    $grouped = ['fail' => [], 'partial' => [], 'pass' => []];

    foreach ($check_results as $check_id => $check) {
      $pct = $check['max'] > 0 ? ($check['score'] / $check['max']) * 100 : 0;
      if ($pct >= 75) {
        $grouped['pass'][$check_id] = $check;
      }
      elseif ($pct >= 50) {
        $grouped['partial'][$check_id] = $check;
      }
      else {
        $grouped['fail'][$check_id] = $check;
      }
    }

    return $grouped;
  }

  /**
   * Builds a collapsible section of check results.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The section title.
   * @param array<string, array{score: int, max: int, details: array<string, mixed>}> $checks
   *   The checks in this group.
   * @param string $status
   *   One of 'fail', 'partial', 'pass'.
   * @param bool $open
   *   Whether the section starts expanded.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function buildCheckSection(string|\Drupal\Core\StringTranslation\TranslatableMarkup $title, array $checks, string $status, bool $open): array {
    $section = [
      '#type' => 'html_tag',
      '#tag' => 'details',
      '#attributes' => [
        'class' => ['analyze-full-report__section', 'analyze-full-report__section--' . $status],
      ],
    ];
    if ($open) {
      $section['#attributes']['open'] = 'open';
    }

    $section['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'summary',
      '#value' => $title,
    ];

    $weight = 0;
    foreach ($checks as $check_id => $check) {
      $section['check_' . $check_id] = $this->buildCheckItem($check_id, $check, $status);
      $section['check_' . $check_id]['#weight'] = $weight++;
    }

    return $section;
  }

  /**
   * Builds a single check item with diagnosis, education, and action.
   *
   * @param string $check_id
   *   The check machine name.
   * @param array{score: int, max: int, details: array<string, mixed>} $check
   *   The check result.
   * @param string $status
   *   One of 'fail', 'partial', 'pass'.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function buildCheckItem(string $check_id, array $check, string $status): array {
    $label = self::CHECK_LABELS[$check_id] ?? $check_id;
    $icon_map = ['pass' => "\xE2\x9C\x93", 'partial' => "\xE2\x97\x8B", 'fail' => "\xE2\x9C\x97"];
    $issues = $check['details']['issues'] ?? [];

    $item = [
      '#type' => 'container',
      '#attributes' => ['class' => ['analyze-check']],
    ];

    $item['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['analyze-check__header']],
    ];
    $item['header']['icon'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $icon_map[$status],
      '#attributes' => ['class' => ['analyze-check__icon', 'analyze-check__icon--' . $status]],
    ];
    $item['header']['name'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => ['class' => ['analyze-check__name']],
    ];
    $item['header']['score'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $check['score'] . '/' . $check['max'],
      '#attributes' => ['class' => ['analyze-check__score']],
    ];

    if (!empty($issues)) {
      $item['diagnosis'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => implode(' ', $issues),
        '#attributes' => ['class' => ['analyze-check__diagnosis']],
      ];
    }
    if ($status !== 'pass' && isset(self::CHECK_WHY[$check_id])) {
      $item['why'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => self::CHECK_WHY[$check_id],
        '#attributes' => ['class' => ['analyze-check__why']],
      ];
    }

    if ($status !== 'pass' && isset(self::RECOMMENDATIONS[$check_id])) {
      $rec_text = self::RECOMMENDATIONS[$check_id];
      if ($check_id === 'meta_completeness') {
        $missing = $check['details']['missing'] ?? [];
        if (!empty($missing)) {
          $rec_text = $this->t('Add missing meta tags: @tags. These help AI systems understand and attribute your content.', [
            '@tags' => implode(', ', $missing),
          ]);
        }
      }
      $item['action'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $rec_text,
        '#attributes' => ['class' => ['analyze-check__action']],
      ];
    }

    $data_parts = $this->buildDataLine($check_id, $check);
    if (!empty($data_parts)) {
      $item['data'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $data_parts,
        '#attributes' => ['class' => ['analyze-check__data']],
      ];
    }

    return $item;
  }

  /**
   * Builds a data summary line for a check.
   */
  private function buildDataLine(string $check_id, array $check): string {
    $details = $check['details'] ?? [];
    $parts = [];

    switch ($check_id) {
      case 'heading_hierarchy':
        if (isset($details['heading_count'])) {
          $parts[] = $this->t('@count headings found', ['@count' => $details['heading_count']]);
        }
        if (isset($details['h1_count'])) {
          $parts[] = $this->t('@count H1', ['@count' => $details['h1_count']]);
        }
        if (isset($details['query_shaped_count'])) {
          $parts[] = $this->t('@count question-shaped', ['@count' => $details['query_shaped_count']]);
        }
        break;

      case 'json_ld':
        if (!empty($details['schemas_found'])) {
          $types = [];
          foreach ($details['schemas_found'] as $type) {
            $types[] = is_array($type) ? implode(', ', $type) : $type;
          }
          $parts[] = $this->t('Found: @types', ['@types' => implode(', ', $types)]);
        }
        break;

      case 'meta_completeness':
        if (!empty($details['present'])) {
          $parts[] = $this->t('Present: @tags', ['@tags' => implode(', ', $details['present'])]);
        }
        break;

      case 'semantic_html':
        if (!empty($details['found'])) {
          $tags = [];
          foreach ($details['found'] as $tag => $count) {
            $tags[] = $tag . ' (' . $count . ')';
          }
          $parts[] = $this->t('Found: @tags', ['@tags' => implode(', ', $tags)]);
        }
        break;

      case 'internal_link_density':
        if (isset($details['internal_links'])) {
          $parts[] = $this->t('@count internal, @ext external', [
            '@count' => $details['internal_links'],
            '@ext' => $details['external_links'] ?? 0,
          ]);
        }
        break;
    }

    return implode(' · ', $parts);
  }

  /**
   * {@inheritdoc}
   */
  public function processEntity(EntityInterface $entity, bool $force_refresh = FALSE): bool {
    $content_hash = $this->contentInspector->generateContentHash($entity);

    if (!$force_refresh && $this->storage->hasValidCache($entity, $content_hash)) {
      return FALSE;
    }

    if ($force_refresh) {
      $this->storage->deleteScores($entity);
    }

    $check_results = $this->runAllChecks($entity);
    if (empty($check_results)) {
      return FALSE;
    }

    $overall = $this->calculateOverallScore($check_results);
    $this->storage->saveScores($entity, $overall, $check_results, $content_hash);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasResults(EntityInterface $entity): bool {
    $content_hash = $this->contentInspector->generateContentHash($entity);
    return $this->storage->hasValidCache($entity, $content_hash);
  }

  /**
   * {@inheritdoc}
   */
  public function countAnalyzedEntities(string $entity_type_id, string $bundle): int {
    return $this->storage->countAnalyzedEntities($entity_type_id, $bundle);
  }

  /**
   * Gets cached results or computes them.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array<string, mixed>|null
   *   Score data or NULL.
   */
  private function getOrComputeResults(EntityInterface $entity): ?array {
    $content_hash = $this->contentInspector->generateContentHash($entity);

    $cached = $this->storage->getValidScores($entity, $content_hash);
    if ($cached !== NULL) {
      return $cached;
    }

    $check_results = $this->runAllChecks($entity);
    if (empty($check_results)) {
      return NULL;
    }

    $overall = $this->calculateOverallScore($check_results);
    $this->storage->saveScores($entity, $overall, $check_results, $content_hash);

    return [
      'overall_score' => $overall,
      'check_results' => $check_results,
    ];
  }

  /**
   * Runs all enabled checks on an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array<string, array{score: int, max: int, details: array<string, mixed>}>
   *   Combined check results.
   */
  private function runAllChecks(EntityInterface $entity): array {
    $results = $this->contentInspector->inspect($entity);

    $config = $this->configFactory->get('analyze_aio.settings');
    $ai_enabled = (bool) $config->get('ai_checks_enabled');
    $checks = $config->get('checks') ?: [];

    if ($ai_enabled && $this->aiProvider !== NULL) {
      if (!empty($checks['citation_readiness'])) {
        $results['citation_readiness'] = $this->checkCitationReadiness($entity);
      }
      if (!empty($checks['tone_authority'])) {
        $results['tone_authority'] = $this->checkToneAuthority($entity);
      }
    }

    return $results;
  }

  /**
   * AI check: citation readiness.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkCitationReadiness(EntityInterface $entity): array {
    $content = $this->contentInspector->getPlainText($entity);
    if (empty($content) || mb_strlen($content) < 100) {
      return ['score' => 0, 'max' => 3, 'details' => ['issues' => ['Content too short for citation analysis.']]];
    }

    $truncated = mb_substr($content, 0, 4000);
    $prompt = <<<EOT
<task>Analyse the following text for AI citation readiness.</task>
<text>
$truncated
</text>

<instructions>
Score each dimension from 0 to 1:
1. citable_facts: Does the text contain specific, extractable facts with numbers, dates, or named sources that an AI could quote directly?
2. source_attribution: Are claims attributed to named sources, studies, or organisations?
3. structured_claims: Are key findings, statistics, or conclusions presented in self-contained sentences that can be extracted without surrounding context?
</instructions>
<output_format>Respond with JSON only: {"citable_facts": number, "source_attribution": number, "structured_claims": number}</output_format>
EOT;

    $ai_result = $this->callAi($prompt);
    if ($ai_result === NULL) {
      return ['score' => 0, 'max' => 3, 'details' => ['issues' => ['AI provider not available.']]];
    }

    $score = 0;
    $details = ['ai_scores' => $ai_result, 'issues' => []];

    if (($ai_result['citable_facts'] ?? 0) >= 0.5) {
      $score++;
    }
    else {
      $details['issues'][] = 'Few cite-able facts found.';
    }
    if (($ai_result['source_attribution'] ?? 0) >= 0.5) {
      $score++;
    }
    else {
      $details['issues'][] = 'Weak source attribution.';
    }
    if (($ai_result['structured_claims'] ?? 0) >= 0.5) {
      $score++;
    }
    else {
      $details['issues'][] = 'Claims not structured as extractable sentences.';
    }

    return ['score' => $score, 'max' => 3, 'details' => $details];
  }

  /**
   * AI check: tone and authority.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkToneAuthority(EntityInterface $entity): array {
    $content = $this->contentInspector->getPlainText($entity);
    if (empty($content) || mb_strlen($content) < 100) {
      return ['score' => 0, 'max' => 3, 'details' => ['issues' => ['Content too short for tone analysis.']]];
    }

    $truncated = mb_substr($content, 0, 4000);
    $prompt = <<<EOT
<task>Analyse the following text for tone authority suitable for AI answer engines.</task>
<text>
$truncated
</text>

<instructions>
Score each dimension from 0 to 1:
1. neutral_authoritative: Is the tone neutral, confident, and authoritative rather than promotional or hyperbolic?
2. specificity: Does the text use precise language, avoid vague qualifiers, and provide concrete details?
3. comprehensiveness: Does the content cover the topic thoroughly, addressing likely follow-up questions?
</instructions>
<output_format>Respond with JSON only: {"neutral_authoritative": number, "specificity": number, "comprehensiveness": number}</output_format>
EOT;

    $ai_result = $this->callAi($prompt);
    if ($ai_result === NULL) {
      return ['score' => 0, 'max' => 3, 'details' => ['issues' => ['AI provider not available.']]];
    }

    $score = 0;
    $details = ['ai_scores' => $ai_result, 'issues' => []];

    if (($ai_result['neutral_authoritative'] ?? 0) >= 0.5) {
      $score++;
    }
    else {
      $details['issues'][] = 'Tone not sufficiently neutral or authoritative.';
    }
    if (($ai_result['specificity'] ?? 0) >= 0.5) {
      $score++;
    }
    else {
      $details['issues'][] = 'Language lacks specificity.';
    }
    if (($ai_result['comprehensiveness'] ?? 0) >= 0.5) {
      $score++;
    }
    else {
      $details['issues'][] = 'Content coverage not comprehensive.';
    }

    return ['score' => $score, 'max' => 3, 'details' => $details];
  }

  /**
   * Calls the AI provider with a prompt.
   *
   * @param string $prompt
   *   The prompt text.
   *
   * @return array<string, float>|null
   *   Decoded response or NULL on failure.
   */
  private function callAi(string $prompt): ?array {
    if ($this->aiProvider === NULL || $this->promptJsonDecoder === NULL) {
      return NULL;
    }

    try {
      if (!$this->aiProvider->hasProvidersForOperationType('chat', TRUE)) {
        return NULL;
      }

      $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
      if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
        return NULL;
      }

      $provider = $this->aiProvider->createInstance($defaults['provider_id']);
      if (method_exists($provider, 'setConfiguration')) {
        $provider->setConfiguration(['temperature' => 0.1]);
      }

      $messages = new ChatInput([new ChatMessage('user', $prompt)]);
      $response = $provider->chat($messages, $defaults['model_id'])->getNormalized();

      $decoded = $this->promptJsonDecoder->decode($response);
      if (!is_array($decoded)) {
        return NULL;
      }

      return $decoded;
    }
    catch (AiRateLimitException $e) {
      throw $e;
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Calculates the overall AIO score from individual check results.
   *
   * @param array<string, array{score: int, max: int, details: array<string, mixed>}> $check_results
   *   Individual check results.
   *
   * @return int
   *   Overall score 0-100.
   */
  private function calculateOverallScore(array $check_results): int {
    $total_score = 0;
    $total_max = 0;

    foreach ($check_results as $check) {
      $total_score += $check['score'];
      $total_max += $check['max'];
    }

    if ($total_max === 0) {
      return 0;
    }

    return (int) round(($total_score / $total_max) * 100);
  }

  /**
   * Creates a fallback status table.
   *
   * @param string $message
   *   The status message.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function createStatusTable(string $message): array {
    return [
      '#theme' => 'analyze_table',
      '#table_title' => 'AI Optimization',
      '#rows' => [
        [
          'label' => 'Status',
          'data' => $message,
        ],
      ],
    ];
  }

}
