<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\pendulum_arc\Rules\KeyphrasePhrase;

/**
 * Reads focus phrases Real-time SEO already stored.
 *
 * The field type is yoast_seo and the property is focus_keyword. That covers
 * the Real-time SEO module and the Drupal CMS SEO Tools recipe, whatever
 * machine name the site gave the field. The module is not required.
 */
final class KeyphraseRead {

  public function __construct(
    private readonly EntityFieldManagerInterface $fields,
  ) {}

  /**
   * Entity types that have at least one yoast_seo field.
   *
   * @return array<int, string>
   */
  public function entityTypes(): array {
    try {
      $map = $this->fields->getFieldMapByFieldType('yoast_seo');
    }
    catch (\Throwable) {
      return [];
    }
    $types = array_keys($map);
    sort($types, SORT_STRING);
    return $types;
  }

  /**
   * Whether this entity can store a focus keyphrase.
   */
  public function hasPhraseField(FieldableEntityInterface $entity): bool {
    foreach ($entity->getFieldDefinitions() as $definition) {
      if ($definition->getType() === 'yoast_seo') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Phrase for one entity. The first non-empty field wins.
   */
  public function phrase(FieldableEntityInterface $entity): string {
    $candidates = [];
    foreach ($entity->getFieldDefinitions() as $definition) {
      if ($definition->getType() !== 'yoast_seo' || !$entity->hasField($definition->getName())) {
        continue;
      }
      foreach ($entity->get($definition->getName()) as $item) {
        if (!$item instanceof FieldItemInterface) {
          continue;
        }
        try {
          $raw = $item->get('focus_keyword')->getValue();
        }
        catch (\Throwable) {
          continue;
        }
        $candidates[] = is_string($raw) ? $raw : '';
      }
    }
    return KeyphrasePhrase::first($candidates);
  }

}
