<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\pendulum_arc\Rules\KeyphraseCollect;
use Drupal\pendulum_arc\Rules\KeyphraseCursor;
use Drupal\pendulum_arc\Rules\KeyphrasePath;

/**
 * Builds the local keyphrase list in small pages. This does not call Arc.
 */
final class KeyphraseScan {

  public const BATCH = 200;

  public function __construct(
    private readonly KeyphraseList $list,
    private readonly KeyphraseRead $read,
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  /**
   * Reads one page. Returns TRUE when another tick is still needed.
   */
  public function tick(string $origin, string $front): bool {
    $state = $this->list->state();
    if (!$state['enabled'] || $state['scan_done']) {
      return FALSE;
    }
    $sources = $state['sources'];
    $cursor = $state['cursor'];
    $index = (int) ($cursor['index'] ?? 0);
    $after = (string) ($cursor['after'] ?? '0');
    $type = $sources[$index] ?? '';
    $count = 0;
    $last = $after;
    if (is_string($type) && $type !== '') {
      foreach ($this->ids($type, $after) as $id) {
        $loaded = $this->entities->getStorage($type)->load($id);
        if ($loaded instanceof ContentEntityInterface) {
          foreach ($this->translations($loaded) as $translation) {
            $this->applyEntity($translation, $origin, $front);
          }
        }
        $last = (string) $id;
        ++$count;
      }
    }
    $next = KeyphraseCursor::advance($index, $after, $count, self::BATCH, $last, count($sources));
    $state['cursor'] = $next;
    $state['scan_done'] = $next['done'];
    $this->list->write($state);
    return !$next['done'];
  }

  /**
   * Refreshes the entity addressed by an internal path such as /node/1.
   */
  public function refreshInternal(string $internal, string $origin, string $front): void {
    if (1 !== preg_match('#^/([A-Za-z0-9_]+)/([^/]+)$#', $internal, $match)) {
      return;
    }
    try {
      $storage = $this->entities->getStorage($match[1]);
    }
    catch (\Throwable) {
      return;
    }
    $entity = $storage->load($match[2]);
    if ($entity instanceof ContentEntityInterface) {
      $this->refresh($entity, $origin, $front);
    }
  }

  /**
   * Refreshes the saved translation.
   */
  public function refresh(ContentEntityInterface $entity, string $origin, string $front): void {
    if (!$this->list->state()['enabled'] || !$this->read->hasPhraseField($entity)) {
      return;
    }
    if ($entity->getEntityType()->isRevisionable() && method_exists($entity, 'isDefaultRevision') && !$entity->isDefaultRevision()) {
      return;
    }
    $this->applyEntity($entity, $origin, $front);
  }

  /**
   * Tombstones every translation before Drupal removes the entity.
   */
  public function forget(ContentEntityInterface $entity, string $origin): void {
    if (!$this->list->state()['enabled'] || !$this->read->hasPhraseField($entity)) {
      return;
    }
    foreach ($this->translations($entity) as $translation) {
      $url = $this->absoluteUrl($translation);
      $path = $url === NULL ? '' : (string) KeyphrasePath::fromUrl($url, $origin);
      $this->list->clearPath(
        $translation->getEntityTypeId(),
        (string) $translation->id(),
        $translation->language()->getId(),
        $path,
      );
    }
  }

  /**
   * @return array<int, int|string>
   */
  private function ids(string $entityTypeId, string $after): array {
    $definition = $this->entities->getDefinition($entityTypeId, FALSE);
    if ($definition === NULL) {
      return [];
    }
    $idKey = $definition->getKey('id');
    if (!is_string($idKey) || $idKey === '') {
      return [];
    }
    $query = $this->entities->getStorage($entityTypeId)->getQuery()->accessCheck(FALSE);
    $published = $definition->getKey('published');
    if (is_string($published) && $published !== '') {
      $query->condition($published, 1);
    }
    if ($after !== '' && $after !== '0') {
      $query->condition($idKey, is_numeric($after) ? (int) $after : $after, '>');
    }
    $query->sort($idKey, 'ASC');
    $query->range(0, self::BATCH);
    return array_values($query->execute());
  }

  /**
   * @return array<int, \Drupal\Core\Entity\ContentEntityInterface>
   */
  private function translations(ContentEntityInterface $entity): array {
    if (!$entity instanceof TranslatableInterface) {
      return [$entity];
    }
    $out = [];
    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      if ($entity->hasTranslation($langcode)) {
        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof ContentEntityInterface) {
          $out[] = $translation;
        }
      }
    }
    return $out === [] ? [$entity] : $out;
  }

  private function applyEntity(ContentEntityInterface $entity, string $origin, string $front): void {
    $kind = $entity->getEntityTypeId();
    $id = (string) $entity->id();
    $langcode = $entity->language()->getId();
    $url = $this->absoluteUrl($entity);
    if (!$this->isPublic($entity)) {
      $path = $url === NULL ? '' : (string) KeyphrasePath::fromUrl($url, $origin);
      $this->list->clearPath($kind, $id, $langcode, $path);
      return;
    }
    if ($url === NULL) {
      return;
    }
    $row = KeyphraseCollect::row($url, $origin, $this->isFront($entity, $front), [
      $this->read->phrase($entity),
    ]);
    $this->apply($kind, $id, $langcode, $row);
  }

  /**
   * @param array{role: string, path: string, phrase: string} $row
   *   Classified row.
   */
  private function apply(string $kind, string $objectId, string $langcode, array $row): void {
    if ($row['role'] === 'skip') {
      return;
    }
    if ($row['role'] === 'focus' || $row['role'] === 'clear-focus') {
      $this->list->clearPath($kind, $objectId, $langcode, '');
      $this->list->setFocus($row['phrase']);
      return;
    }
    if ($row['role'] === 'clear') {
      $this->list->clearPath($kind, $objectId, $langcode, $row['path']);
      return;
    }
    $this->list->put($kind, $objectId, $langcode, $row['path'], $row['phrase']);
  }

  private function absoluteUrl(ContentEntityInterface $entity): ?string {
    if (!$entity->hasLinkTemplate('canonical')) {
      return NULL;
    }
    try {
      return $entity->toUrl('canonical', [
        'absolute' => TRUE,
        'language' => $entity->language(),
      ])->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private function isFront(ContentEntityInterface $entity, string $front): bool {
    if ($front === '' || $front === '<front>' || $front === '/') {
      return FALSE;
    }
    try {
      $internal = '/' . ltrim($entity->toUrl('canonical')->getInternalPath(), '/');
    }
    catch (\Throwable) {
      return FALSE;
    }
    return $internal === $front;
  }

  private function isPublic(ContentEntityInterface $entity): bool {
    if (method_exists($entity, 'isPublished')) {
      return (bool) $entity->isPublished();
    }
    return TRUE;
  }

}
