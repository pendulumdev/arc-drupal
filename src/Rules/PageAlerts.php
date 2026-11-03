<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Builds the alert list. Only the keyphrase alert adds to the Arc menu count.
 */
final class PageAlerts {

  /**
   * The keyphrase list needs a sync click.
   */
  public static function keyphrasePending(
    bool $enabled,
    bool $scanDone,
    bool $connected,
    string $focus,
    string $focusSynced,
    bool $dirty,
    bool $storedDiverged,
  ): bool {
    if (!$enabled || !$scanDone || !$connected) {
      return FALSE;
    }
    return $dirty || $storedDiverged || $focus !== $focusSynced;
  }

  /**
   * @param array<int, array{count?: bool}> $alerts
   *   Alerts for this screen.
   */
  public static function count(array $alerts): int {
    $count = 0;
    foreach ($alerts as $alert) {
      if (!empty($alert['count'])) {
        ++$count;
      }
    }
    return $count;
  }

  /**
   * Flash notices stay in the banner. The keyphrase alert is the one that counts.
   *
   * @param array{status?: string, message?: string}|null $flash
   *   One-shot notice, or NULL.
   *
   * @return array<int, array{id: string, tone: string, message: string, count: bool, href?: string, hrefLabel?: string}>
   */
  public static function collect(
    ?array $flash,
    bool $keyphrases,
    string $connectUrl,
    bool $onConnect,
    bool $googleMissing = FALSE,
    bool $arcMissing = FALSE,
  ): array {
    $alerts = [];
    if ($arcMissing) {
      $alert = [
        'id' => 'arc',
        'tone' => 'info',
        'message' => 'Boost your online visibility. Connect your Drupal website with Arc for reports, issues, and recommendations across Analytics, Accessibility, SEO, AI readability, Security, and Brand cohesion.',
        'count' => FALSE,
      ];
      if (!$onConnect && $connectUrl !== '') {
        $alert['href'] = $connectUrl;
        $alert['hrefLabel'] = 'Connect with Arc';
      }
      $alerts[] = $alert;
    }
    if (is_array($flash) && (string) ($flash['message'] ?? '') !== '') {
      $alerts[] = [
        'id' => 'flash',
        'tone' => self::tone((string) ($flash['status'] ?? 'info')),
        'message' => (string) $flash['message'],
        'count' => FALSE,
      ];
    }
    if ($keyphrases) {
      $alert = [
        'id' => 'keyphrases',
        'tone' => 'info',
        'message' => 'Keyphrases on this site differ from Arc.',
        'count' => TRUE,
      ];
      if (!$onConnect && $connectUrl !== '') {
        $alert['href'] = $connectUrl;
        $alert['hrefLabel'] = 'Sync with Arc';
      }
      $alerts[] = $alert;
    }
    if ($googleMissing) {
      $alerts[] = [
        'id' => 'google',
        'tone' => 'info',
        'message' => 'Connect your Google account to get GA4 and Search Console insights and improve your rankings.',
        'count' => FALSE,
      ];
    }
    return $alerts;
  }

  /**
   * Banner tone. Success uses the info banner.
   */
  private static function tone(string $status): string {
    if ($status === 'error' || $status === 'warning') {
      return $status;
    }
    return 'info';
  }

}
