<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Maps draft codes to admin-facing sentences.
 */
final class FieldMessages {

  /**
   * One sentence for a code.
   *
   * @param string $code
   *   Draft error code.
   */
  public static function for_code(string $code): string {
    switch ($code) {
      case SiteDraft::NAME_SHORT:
        return 'Site name must be at least 2 characters.';

      case SiteDraft::NAME_LONG:
        return 'Site name must be at most 120 characters.';

      case SiteDraft::URL:
        return 'Enter a full http or https URL.';

      case SiteDraft::PROJECT:
      case SiteDraft::PROJECT_UNKNOWN:
        return 'Choose a project.';

      default:
        return 'Check this field.';
    }
  }

  /**
   * Codes to sentences.
   *
   * @param array<string, string> $codes
   *   Field codes.
   *
   * @return array<string, string>
   */
  public static function map(array $codes): array {
    $out = [];
    foreach ($codes as $field => $code) {
      $out[$field] = self::for_code((string) $code);
    }
    return $out;
  }

}
