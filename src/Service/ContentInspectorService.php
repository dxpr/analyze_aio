<?php

declare(strict_types=1);

namespace Drupal\analyze_aio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Deterministic content inspector for AI optimization checks.
 */
final class ContentInspectorService {

  use DependencySerializationTrait;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RendererInterface $renderer,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Runs all enabled deterministic checks on an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to inspect.
   *
   * @return array<string, array{score: int, max: int, details: array<string, mixed>}>
   *   Keyed array of check results.
   */
  public function inspect(EntityInterface $entity): array {
    $html = $this->renderEntity($entity);
    if (empty($html)) {
      return [];
    }

    $config = $this->configFactory->get('analyze_aio.settings');
    $checks = $config->get('checks') ?: [];

    $doc = new \DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    $results = [];

    if (!empty($checks['heading_hierarchy'])) {
      $results['heading_hierarchy'] = $this->checkHeadingHierarchy($doc, $entity);
    }
    if (!empty($checks['json_ld'])) {
      $results['json_ld'] = $this->checkJsonLd($doc);
    }
    if (!empty($checks['meta_completeness'])) {
      $results['meta_completeness'] = $this->checkMetaCompleteness($doc, $entity);
    }
    if (!empty($checks['semantic_html'])) {
      $results['semantic_html'] = $this->checkSemanticHtml($doc);
    }
    if (!empty($checks['faq_detection'])) {
      $results['faq_detection'] = $this->checkFaqDetection($doc, $html);
    }
    if (!empty($checks['internal_link_density'])) {
      $results['internal_link_density'] = $this->checkInternalLinkDensity($doc);
    }

    return $results;
  }

  /**
   * Checks heading hierarchy for proper nesting and query-shaped patterns.
   *
   * Drupal renders the entity label as the page H1 via the page title block,
   * but the entity view builder does not include it. We count the entity label
   * as the H1 and scan the rendered fields for H2-H6.
   *
   * @param \DOMDocument $doc
   *   The parsed HTML document.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity (its label is the page H1).
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkHeadingHierarchy(\DOMDocument $doc, EntityInterface $entity): array {
    $headings = [];

    $entity_label = trim((string) $entity->label());
    if (!empty($entity_label)) {
      $headings[] = ['level' => 1, 'text' => $entity_label];
    }

    for ($level = 2; $level <= 6; $level++) {
      $elements = $doc->getElementsByTagName('h' . $level);
      foreach ($elements as $el) {
        $headings[] = [
          'level' => $level,
          'text' => trim($el->textContent),
        ];
      }
    }

    $score = 0;
    $max = 4;
    $h1_count = !empty($entity_label) ? 1 : 0;
    $details = [
      'heading_count' => count($headings),
      'h1_count' => $h1_count,
      'issues' => [],
      'query_shaped_count' => 0,
    ];

    if (count($headings) > 0) {
      $score++;
    }
    else {
      $details['issues'][] = 'No headings found.';
    }

    if ($h1_count === 1) {
      $score++;
    }
    else {
      $details['issues'][] = 'No H1 heading found (entity has no title).';
    }

    $levels_used = array_unique(array_column($headings, 'level'));
    sort($levels_used);
    $has_skip = FALSE;
    if (count($levels_used) > 1) {
      for ($i = 1; $i < count($levels_used); $i++) {
        if ($levels_used[$i] - $levels_used[$i - 1] > 1) {
          $has_skip = TRUE;
          $details['issues'][] = sprintf('Heading level skip: H%d to H%d.', $levels_used[$i - 1], $levels_used[$i]);
        }
      }
    }
    if (!$has_skip && count($headings) > 1) {
      $score++;
    }

    $query_pattern = '/^(what|how|why|when|where|who|which|can|do|does|is|are|should|will|would)\b/i';
    $query_count = 0;
    foreach ($headings as $h) {
      if (preg_match($query_pattern, $h['text']) || str_contains($h['text'], '?')) {
        $query_count++;
      }
    }
    $details['query_shaped_count'] = $query_count;
    if ($query_count > 0) {
      $score++;
    }

    return ['score' => $score, 'max' => $max, 'details' => $details];
  }

  /**
   * Checks for JSON-LD structured data presence.
   *
   * @param \DOMDocument $doc
   *   The parsed HTML document.
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkJsonLd(\DOMDocument $doc): array {
    $score = 0;
    $max = 3;
    $details = [
      'schemas_found' => [],
      'issues' => [],
    ];

    $scripts = $doc->getElementsByTagName('script');
    $json_ld_items = [];

    foreach ($scripts as $script) {
      if ($script->getAttribute('type') === 'application/ld+json') {
        $content = trim($script->textContent);
        $decoded = json_decode($content, TRUE);
        if ($decoded !== NULL) {
          $json_ld_items[] = $decoded;
        }
      }
    }

    if (!empty($json_ld_items)) {
      $score++;
    }
    else {
      $details['issues'][] = 'No JSON-LD structured data found.';
      return ['score' => $score, 'max' => $max, 'details' => $details];
    }

    $types = [];
    foreach ($json_ld_items as $item) {
      if (isset($item['@graph'])) {
        foreach ($item['@graph'] as $graph_item) {
          if (isset($graph_item['@type'])) {
            $types[] = $graph_item['@type'];
          }
        }
      }
      elseif (isset($item['@type'])) {
        $types[] = $item['@type'];
      }
    }
    $details['schemas_found'] = $types;

    $content_types = ['Article', 'BlogPosting', 'NewsArticle', 'HowTo', 'Product', 'Recipe', 'Review', 'Report', 'TechArticle', 'ScholarlyArticle'];
    $has_content_type = FALSE;
    foreach ($types as $type) {
      $flat_types = is_array($type) ? $type : [$type];
      foreach ($flat_types as $t) {
        if (in_array($t, $content_types, TRUE)) {
          $has_content_type = TRUE;
        }
      }
    }
    if ($has_content_type) {
      $score++;
    }
    else {
      $details['issues'][] = 'No content-type schema found (Article, HowTo, Product, etc.).';
    }

    $ai_friendly = ['FAQPage', 'HowTo', 'QAPage'];
    $has_ai_friendly = FALSE;
    foreach ($types as $type) {
      $flat_types = is_array($type) ? $type : [$type];
      foreach ($flat_types as $t) {
        if (in_array($t, $ai_friendly, TRUE)) {
          $has_ai_friendly = TRUE;
        }
      }
    }
    if ($has_ai_friendly) {
      $score++;
    }

    return ['score' => $score, 'max' => $max, 'details' => $details];
  }

  /**
   * Checks meta tag completeness for AI discoverability.
   *
   * Drupal renders the entity label as the page title, but the entity view
   * builder does not include a <title> element. We use the entity label
   * directly for the title check. Other meta tags (description, OG, canonical)
   * depend on contributed modules like metatag and are checked in the DOM.
   *
   * @param \DOMDocument $doc
   *   The parsed HTML document.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity (its label is the page title).
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkMetaCompleteness(\DOMDocument $doc, EntityInterface $entity): array {
    $score = 0;
    $max = 4;
    $details = [
      'present' => [],
      'missing' => [],
      'issues' => [],
    ];

    $has_title = !empty(trim((string) $entity->label()));
    if ($has_title) {
      $score++;
      $details['present'][] = 'title';
    }
    else {
      $details['missing'][] = 'title';
    }

    $metas = $doc->getElementsByTagName('meta');
    $meta_map = [];
    foreach ($metas as $meta) {
      $name = $meta->getAttribute('name') ?: $meta->getAttribute('property');
      if ($name) {
        $meta_map[strtolower($name)] = $meta->getAttribute('content');
      }
    }

    if (!empty($meta_map['description'])) {
      $score++;
      $details['present'][] = 'description';
    }
    else {
      $details['missing'][] = 'description';
    }

    $og_tags = ['og:title', 'og:description', 'og:type'];
    $og_present = 0;
    foreach ($og_tags as $tag) {
      if (!empty($meta_map[$tag])) {
        $og_present++;
        $details['present'][] = $tag;
      }
      else {
        $details['missing'][] = $tag;
      }
    }
    if ($og_present >= 2) {
      $score++;
    }

    $links = $doc->getElementsByTagName('link');
    $has_canonical = FALSE;
    foreach ($links as $link) {
      if ($link->getAttribute('rel') === 'canonical') {
        $has_canonical = TRUE;
        $details['present'][] = 'canonical';
        break;
      }
    }
    if ($has_canonical) {
      $score++;
    }
    else {
      $details['missing'][] = 'canonical';
    }

    if (!empty($details['missing'])) {
      $details['issues'][] = 'Missing: ' . implode(', ', $details['missing']) . '.';
    }

    return ['score' => $score, 'max' => $max, 'details' => $details];
  }

  /**
   * Checks semantic HTML element usage.
   *
   * @param \DOMDocument $doc
   *   The parsed HTML document.
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkSemanticHtml(\DOMDocument $doc): array {
    $score = 0;
    $max = 3;
    $semantic_tags = ['article', 'section', 'nav', 'aside', 'figure', 'figcaption', 'main', 'header', 'footer', 'time', 'mark', 'details', 'summary'];

    $found = [];
    foreach ($semantic_tags as $tag) {
      $elements = $doc->getElementsByTagName($tag);
      if ($elements->length > 0) {
        $found[$tag] = $elements->length;
      }
    }

    $details = [
      'found' => $found,
      'issues' => [],
    ];

    if (!empty($found)) {
      $score++;
    }
    else {
      $details['issues'][] = 'No semantic HTML elements found (article, section, figure, etc.).';
    }

    if (isset($found['article']) || isset($found['section'])) {
      $score++;
    }
    else {
      $details['issues'][] = 'No article or section elements found.';
    }

    $supplementary = ['figure', 'time', 'aside', 'details'];
    $has_supplementary = FALSE;
    foreach ($supplementary as $tag) {
      if (isset($found[$tag])) {
        $has_supplementary = TRUE;
        break;
      }
    }
    if ($has_supplementary) {
      $score++;
    }

    return ['score' => $score, 'max' => $max, 'details' => $details];
  }

  /**
   * Checks for FAQ-style content patterns.
   *
   * @param \DOMDocument $doc
   *   The parsed HTML document.
   * @param string $html
   *   The raw HTML string.
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkFaqDetection(\DOMDocument $doc, string $html): array {
    $score = 0;
    $max = 2;
    $details = [
      'faq_schema' => FALSE,
      'faq_html_patterns' => 0,
      'issues' => [],
    ];

    $scripts = $doc->getElementsByTagName('script');
    foreach ($scripts as $script) {
      if ($script->getAttribute('type') === 'application/ld+json') {
        $content = trim($script->textContent);
        if (str_contains($content, '"FAQPage"') || str_contains($content, '"QAPage"')) {
          $details['faq_schema'] = TRUE;
          $score++;
          break;
        }
      }
    }

    $faq_patterns = 0;

    $dls = $doc->getElementsByTagName('dl');
    if ($dls->length > 0) {
      $faq_patterns += $dls->length;
    }

    $detail_elements = $doc->getElementsByTagName('details');
    if ($detail_elements->length > 0) {
      $faq_patterns += $detail_elements->length;
    }

    for ($level = 2; $level <= 4; $level++) {
      $elements = $doc->getElementsByTagName('h' . $level);
      foreach ($elements as $el) {
        if (str_contains(trim($el->textContent), '?')) {
          $faq_patterns++;
        }
      }
    }

    $details['faq_html_patterns'] = $faq_patterns;
    if ($faq_patterns >= 2) {
      $score++;
    }
    elseif ($faq_patterns === 0 && !$details['faq_schema']) {
      $details['issues'][] = 'No FAQ-style content patterns found.';
    }

    return ['score' => $score, 'max' => $max, 'details' => $details];
  }

  /**
   * Checks internal link density.
   *
   * @param \DOMDocument $doc
   *   The parsed HTML document.
   *
   * @return array{score: int, max: int, details: array<string, mixed>}
   *   Check result.
   */
  private function checkInternalLinkDensity(\DOMDocument $doc): array {
    $score = 0;
    $max = 3;

    $links = $doc->getElementsByTagName('a');
    $internal_count = 0;
    $external_count = 0;

    $request = $this->requestStack->getCurrentRequest();
    $site_host = $request ? $request->getHost() : '';

    foreach ($links as $link) {
      $href = $link->getAttribute('href');
      if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
        continue;
      }

      if ($this->isInternalUrl($href, $site_host)) {
        $internal_count++;
      }
      else {
        $external_count++;
      }
    }

    $total = $internal_count + $external_count;
    $details = [
      'internal_links' => $internal_count,
      'external_links' => $external_count,
      'total_links' => $total,
      'issues' => [],
    ];

    if ($internal_count > 0) {
      $score++;
    }
    else {
      $details['issues'][] = 'No internal links found.';
    }

    if ($internal_count >= 3) {
      $score++;
    }

    if ($external_count > 0) {
      $score++;
    }

    return ['score' => $score, 'max' => $max, 'details' => $details];
  }

  /**
   * Determines if a URL is internal.
   *
   * @param string $url
   *   The URL to classify.
   * @param string $site_host
   *   The site host.
   *
   * @return bool
   *   TRUE if the URL is internal.
   */
  private function isInternalUrl(string $url, string $site_host): bool {
    if (!preg_match('/^https?:\/\//i', $url)) {
      return TRUE;
    }
    $parsed = parse_url($url);
    if (!isset($parsed['host'])) {
      return TRUE;
    }
    return strcasecmp($parsed['host'], $site_host) === 0;
  }

  /**
   * Gets the plain text content of an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   Plain text content.
   */
  public function getPlainText(EntityInterface $entity): string {
    $html = $this->renderEntity($entity);
    $text = strip_tags($html);
    $text = str_replace('&nbsp;', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    return trim($text);
  }

  /**
   * Generates a content hash for cache invalidation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   SHA-256 hash of the rendered content.
   */
  public function generateContentHash(EntityInterface $entity): string {
    $html = $this->renderEntity($entity);
    return hash('sha256', $html);
  }

  /**
   * Renders an entity and returns the HTML.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to render.
   *
   * @return string
   *   The rendered HTML.
   */
  private function renderEntity(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();
    $view_builder = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
    $view = $view_builder->view($entity, 'default', $langcode);
    $rendered = $this->renderer->renderInIsolation($view);
    return (string) $rendered;
  }

}
