<?php

/**
 * @file
 * Provide views data for analyze_aio module.
 */

/**
 * Implements hook_views_data().
 */
function analyze_aio_views_data() {
  $data = [];

  $data['analyze_aio_scores']['table']['group'] = t('AI Optimization');
  $data['analyze_aio_scores']['table']['base'] = [
    'field' => 'entity_id',
    'title' => t('AI Optimization Scores'),
    'help' => t('AI optimization readiness scores for content entities.'),
  ];

  $data['analyze_aio_scores']['entity_type'] = [
    'title' => t('Entity Type'),
    'help' => t('The type of entity that was analysed.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
    'argument' => ['id' => 'string'],
  ];

  $data['analyze_aio_scores']['entity_id'] = [
    'title' => t('Entity ID'),
    'help' => t('The ID of the analysed entity.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'argument' => ['id' => 'numeric'],
    'relationship' => [
      'id' => 'standard',
      'base' => 'node_field_data',
      'base field' => 'nid',
      'field' => 'entity_id',
      'label' => t('Content'),
      'title' => t('Content'),
      'help' => t('The content entity that was analysed.'),
    ],
  ];

  $data['analyze_aio_scores']['bundle'] = [
    'title' => t('Bundle'),
    'help' => t('The entity bundle.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
    'argument' => ['id' => 'string'],
  ];

  $data['analyze_aio_scores']['overall_score'] = [
    'title' => t('AIO Score'),
    'help' => t('The overall AI optimization readiness score (0-100).'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'argument' => ['id' => 'numeric'],
  ];

  $data['analyze_aio_scores']['content_hash'] = [
    'title' => t('Content Hash'),
    'help' => t('SHA-256 hash of the rendered content.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['analyze_aio_scores']['last_checked'] = [
    'title' => t('Last Checked'),
    'help' => t('When the entity was last analysed.'),
    'field' => [
      'id' => 'date',
      'click sortable' => TRUE,
    ],
    'filter' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
    'argument' => ['id' => 'date'],
  ];

  return $data;
}
