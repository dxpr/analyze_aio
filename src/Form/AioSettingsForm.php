<?php

declare(strict_types=1);

namespace Drupal\analyze_aio\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure AI Optimization readiness checks.
 */
final class AioSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'analyze_aio_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['analyze_aio.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('analyze_aio.settings');
    $checks = $config->get('checks') ?: [];

    try {
      $reports_url = Url::fromRoute('view.aio_results.page_1');
      if ($reports_url->access()) {
        $form['actions_top'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['form-actions']],
          '#weight' => -10,
          'report_link' => [
            '#type' => 'link',
            '#title' => $this->t('View reports'),
            '#url' => $reports_url,
            '#attributes' => [
              'class' => ['button', 'button--small', 'button--primary'],
            ],
          ],
        ];
      }
    }
    catch (\Exception $e) {
    }

    $form['deterministic'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Deterministic checks'),
      '#description' => $this->t('These checks run without an AI provider and analyse the HTML output of each entity.'),
    ];

    $deterministic_checks = [
      'heading_hierarchy' => [
        'title' => $this->t('Heading hierarchy'),
        'description' => $this->t('Checks for proper H1 usage, heading nesting, and query-shaped headings.'),
      ],
      'json_ld' => [
        'title' => $this->t('Structured data (JSON-LD)'),
        'description' => $this->t('Checks for JSON-LD structured data, content-type schemas, and AI-friendly schemas (FAQPage, HowTo).'),
      ],
      'meta_completeness' => [
        'title' => $this->t('Meta tag completeness'),
        'description' => $this->t('Checks for title, meta description, Open Graph tags, and canonical link.'),
      ],
      'semantic_html' => [
        'title' => $this->t('Semantic HTML'),
        'description' => $this->t('Checks for semantic HTML5 elements (article, section, figure, time, etc.).'),
      ],
      'faq_detection' => [
        'title' => $this->t('FAQ patterns'),
        'description' => $this->t('Detects FAQ schema and question/answer HTML patterns.'),
      ],
      'internal_link_density' => [
        'title' => $this->t('Link density'),
        'description' => $this->t('Checks internal and external link counts for AI crawling context.'),
      ],
    ];

    foreach ($deterministic_checks as $key => $info) {
      $form['deterministic'][$key] = [
        '#type' => 'checkbox',
        '#title' => $info['title'],
        '#description' => $info['description'],
        '#default_value' => $checks[$key] ?? TRUE,
      ];
    }

    $form['ai'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI-powered checks'),
      '#description' => $this->t('These checks require the <a href="@url">AI module</a> with a configured chat provider.', [
        '@url' => 'https://www.drupal.org/project/ai',
      ]),
    ];

    $ai_module_installed = $this->moduleHandler->moduleExists('ai');

    $form['ai']['ai_checks_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI-powered checks'),
      '#default_value' => $config->get('ai_checks_enabled') ?? FALSE,
      '#disabled' => !$ai_module_installed,
      '#description' => $ai_module_installed
        ? $this->t('Use AI to evaluate citation readiness and tone authority.')
        : $this->t('Install the <a href="@url">AI module</a> to enable these checks.', [
          '@url' => 'https://www.drupal.org/project/ai',
        ]),
    ];

    $ai_checks = [
      'citation_readiness' => [
        'title' => $this->t('Citation readiness'),
        'description' => $this->t('AI evaluates whether the content contains cite-able facts with sources.'),
      ],
      'tone_authority' => [
        'title' => $this->t('Tone and authority'),
        'description' => $this->t('AI evaluates whether the tone is neutral and authoritative.'),
      ],
    ];

    foreach ($ai_checks as $key => $info) {
      $form['ai'][$key] = [
        '#type' => 'checkbox',
        '#title' => $info['title'],
        '#description' => $info['description'],
        '#default_value' => $checks[$key] ?? TRUE,
        '#states' => [
          'visible' => [
            ':input[name="ai_checks_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $check_keys = [
      'heading_hierarchy',
      'json_ld',
      'meta_completeness',
      'semantic_html',
      'faq_detection',
      'internal_link_density',
      'citation_readiness',
      'tone_authority',
    ];

    $checks = [];
    foreach ($check_keys as $key) {
      $checks[$key] = (bool) $form_state->getValue($key);
    }

    $this->config('analyze_aio.settings')
      ->set('checks', $checks)
      ->set('ai_checks_enabled', (bool) $form_state->getValue('ai_checks_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
