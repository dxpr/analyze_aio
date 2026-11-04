<?php

declare(strict_types=1);

namespace Drupal\analyze_aio\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;

/**
 * Storage service for AI optimization readiness scores.
 */
final class AioStorageService {

  use DependencySerializationTrait;

  public function __construct(
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Gets stored scores for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array<string, mixed>|null
   *   Score data or NULL if not found.
   */
  public function getScores(EntityInterface $entity): ?array {
    if (!$this->database->schema()->tableExists('analyze_aio_scores')) {
      return NULL;
    }

    $row = $this->database->select('analyze_aio_scores', 's')
      ->fields('s')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', $entity->id())
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      return NULL;
    }

    return [
      'overall_score' => (int) $row['overall_score'],
      'check_results' => json_decode($row['check_results'] ?? '{}', TRUE) ?: [],
      'content_hash' => $row['content_hash'],
      'last_checked' => (int) $row['last_checked'],
    ];
  }

  /**
   * Gets scores only if the content hash still matches.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $current_hash
   *   The current content hash.
   *
   * @return array<string, mixed>|null
   *   Valid score data or NULL.
   */
  public function getValidScores(EntityInterface $entity, string $current_hash): ?array {
    $scores = $this->getScores($entity);
    if ($scores === NULL) {
      return NULL;
    }

    if ($scores['content_hash'] !== $current_hash) {
      return NULL;
    }

    return $scores;
  }

  /**
   * Saves scores for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param int $overall_score
   *   The overall AIO score (0-100).
   * @param array<string, array<string, mixed>> $check_results
   *   Individual check results.
   * @param string $content_hash
   *   SHA-256 hash of the rendered content.
   */
  public function saveScores(EntityInterface $entity, int $overall_score, array $check_results, string $content_hash): void {
    if (!$this->database->schema()->tableExists('analyze_aio_scores')) {
      return;
    }

    $this->database->merge('analyze_aio_scores')
      ->keys([
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),
      ])
      ->fields([
        'bundle' => $entity->bundle(),
        'content_hash' => $content_hash,
        'overall_score' => $overall_score,
        'check_results' => json_encode($check_results),
        'last_checked' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Deletes scores for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function deleteScores(EntityInterface $entity): void {
    if (!$this->database->schema()->tableExists('analyze_aio_scores')) {
      return;
    }

    $this->database->delete('analyze_aio_scores')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', $entity->id())
      ->execute();
  }

  /**
   * Checks whether valid cached results exist for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $current_hash
   *   The current content hash.
   *
   * @return bool
   *   TRUE if valid cached results exist.
   */
  public function hasValidCache(EntityInterface $entity, string $current_hash): bool {
    return $this->getValidScores($entity, $current_hash) !== NULL;
  }

  /**
   * Counts entities with stored results for a given bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return int
   *   Number of analysed entities.
   */
  public function countAnalyzedEntities(string $entity_type_id, string $bundle): int {
    if (!$this->database->schema()->tableExists('analyze_aio_scores')) {
      return 0;
    }

    return (int) $this->database->select('analyze_aio_scores', 's')
      ->condition('entity_type', $entity_type_id)
      ->condition('bundle', $bundle)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
