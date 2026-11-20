<?php

use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\analyze_aio\Hook\AnalyzeAioViewsHooks;

/**
 * @file
 * Provide views data for analyze_aio module.
 */
/**
 * Implements hook_views_data().
 */
#[LegacyHook]
function analyze_aio_views_data()
{
    return \Drupal::service(AnalyzeAioViewsHooks::class)->viewsData();
}
