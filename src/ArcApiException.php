<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Safe message and HTTP status from Arc.
 */
final class ArcApiException extends \RuntimeException {

  /**
   * Arc status, or 0 when the host could not be reached.
   *
   * @var int
   */
  private int $statusCode;

  /**
   * @param string $message
   *   Text safe to show an admin.
   * @param int $statusCode
   *   HTTP status, or 0.
   */
  public function __construct(string $message, int $statusCode = 0) {
    parent::__construct($message);
    $this->statusCode = $statusCode;
  }

  /**
   * HTTP status, or 0 for a transport failure.
   */
  public function statusCode(): int {
    return $this->statusCode;
  }

}
