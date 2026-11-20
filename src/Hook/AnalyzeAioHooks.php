<?php

namespace Drupal\analyze_aio\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\views\ViewExecutable;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
/**
 * Hook implementations for analyze_aio.
 */
class AnalyzeAioHooks
{
    use StringTranslationTrait;
    /**
     * Implements hook_help().
     */
    #[Hook('help')]
    public function help($route_name)
    {
        if ($route_name === 'help.page.analyze_aio') {
            return '<p>' . $this->t('Analyses content for AI optimization readiness: heading structure, structured data, semantic HTML, citation quality, and tone authority.') . '</p>';
        }
    }
    /**
     * Implements hook_entity_update().
     */
    #[Hook('entity_update')]
    public function entityUpdate(\Drupal\Core\Entity\EntityInterface $entity): void
    {
        if (_analyze_aio_is_supported_entity($entity)) {
            \Drupal::service('analyze_aio.storage')->deleteScores($entity);
        }
    }
    /**
     * Implements hook_entity_delete().
     */
    #[Hook('entity_delete')]
    public function entityDelete(\Drupal\Core\Entity\EntityInterface $entity): void
    {
        if (_analyze_aio_is_supported_entity($entity)) {
            \Drupal::service('analyze_aio.storage')->deleteScores($entity);
        }
    }
    /**
     * Implements hook_views_pre_view().
     */
    #[Hook('views_pre_view')]
    public function viewsPreView(\Drupal\views\ViewExecutable $view, $display_id, array &$args)
    {
        if ($view->id() === 'aio_results' && $display_id === 'page_1') {
            $current_user = \Drupal::currentUser();
            if ($current_user->hasPermission('administer analyze')) {
                $settings_url = \Drupal\Core\Url::fromRoute('analyze_aio.settings');
                if ($settings_url->access()) {
                    $link = \Drupal\Core\Link::fromTextAndUrl($this->t('Configure settings'), $settings_url);
                    $link = $link->toRenderable();
                    $link['#attributes']['class'][] = 'button';
                    $link['#attributes']['class'][] = 'button--small';
                    $link['#attributes']['class'][] = 'button--primary';
                    $view->attachment_before = [
                        '#type' => 'container',
                        '#attributes' => [
                            'class' => [
                                'form-actions',
                                'views-configure-actions',
                            ],
                        ],
                        '#weight' => -10,
                        'configure_link' => $link,
                        '#attached' => [
                            'library' => [
                                'system/base',
                            ],
                        ],
                    ];
                }
            }
        }
    }
    /**
     * Implements hook_views_color_scale_popover_alter().
     */
    #[Hook('views_color_scale_popover_alter')]
    public function viewsColorScalePopoverAlter(array &$content, array $context): void
    {
        if ($context['view']->storage->get('base_table') !== 'analyze_aio_scores') {
            return;
        }
        $content = [
            '#theme' => 'analyze_gauge',
            '#caption' => $context['field_options']['label'] ?? '',
            '#range_min_label' => $this->t('Not Ready'),
            '#range_mid_label' => $this->t('Partial'),
            '#range_max_label' => $this->t('AI Ready'),
            '#range_min' => $context['min'],
            '#value' => $context['position'],
            '#display_value' => $context['display_value'],
            '#range_max' => $context['max'],
            '#attached' => [
                'library' => [
                    'analyze/gauge-popover',
                ],
            ],
        ];
    }
}
