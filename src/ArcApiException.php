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
   * Arc error code, or an empty string.
   */
  private string $errorCode;

  /**
   * @param string $message
   *   Text safe to show an admin.
   * @param int $statusCode
   *   HTTP status, or 0.
   * @param string $errorCode
   *   Arc error code, such as INSUFFICIENT_SCOPE.
   */
  public function __construct(string $message, int $statusCode = 0, string $errorCode = '') {
    parent::__construct($message);
    $this->statusCode = $statusCode;
    $this->errorCode = $errorCode;
  }

  /**
   * HTTP status, or 0 for a transport failure.
   */
  public function statusCode(): int {
    return $this->statusCode;
  }

  /**
   * Arc error code when the body included one.
   */
  public function errorCode(): string {
    return $this->errorCode;
  }

}
