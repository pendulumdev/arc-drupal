<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\pendulum_arc\Rules\ConsentCatalog;
use Drupal\pendulum_arc\Rules\ConsentSettings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cookie consent settings in State. Detection writes during anonymous views,
 * so this is not exported config.
 */
final class ConsentStore {

  public const STATE_KEY = 'pendulum_arc.consent';

  /**
   * @var array<string, mixed>|null
   */
  private ?array $cache = NULL;

  public function __construct(
    private readonly StateInterface $state,
    private readonly ModuleHandlerInterface $modules,
    private readonly CacheBackendInterface $assets,
    private readonly CacheTagsInvalidatorInterface $tags,
    private readonly ?CacheBackendInterface $pageCache = NULL,
    private readonly ?CacheBackendInterface $dynamicPageCache = NULL,
  ) {}

  /**
   * Default stylesheet shipped with the module.
   */
  public function defaultCss(): string {
    $path = $this->modules->getModule('pendulum_arc')->getPath() . '/assets/cookie-consent.css';
    if (!is_readable($path)) {
      return '';
    }
    $css = file_get_contents($path);
    return is_string($css) ? $css : '';
  }

  /**
   * Cookie names on this request. Names that are not a simple token are dropped.
   *
   * @return list<string>
   */
  public static function cookieNames(Request $request): array {
    $names = [];
    foreach (array_keys($request->cookies->all()) as $name) {
      $raw = str_replace("\0", '', (string) $name);
      if (1 === preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $raw)) {
        $names[] = $raw;
      }
    }
    return $names;
  }

  /**
   * Normalized settings for this request.
   *
   * @return array<string, mixed>
   */
  public function settings(): array {
    if (NULL !== $this->cache) {
      return $this->cache;
    }
    $stored = $this->state->get(self::STATE_KEY);
    $normalized = ConsentSettings::normalize($stored, $this->defaultCss());
    $savedCss = is_array($stored) ? (string) ($stored['custom_css'] ?? '') : '';
    if (is_array($stored) && $savedCss !== (string) $normalized['custom_css']) {
      $this->write($normalized);
      return $this->cache ?? $normalized;
    }
    $this->cache = $normalized;
    return $this->cache;
  }

  /**
   * Settings for the admin screen. The icon URL is already stored.
   *
   * @return array<string, mixed>
   */
  public function view(): array {
    return $this->settings();
  }

  /**
   * Saves a form payload without changing the consent version.
   *
   * @param array<string, mixed> $input
   *   Form payload.
   *
   * @return array<string, mixed>
   */
  public function save(array $input): array {
    $current = $this->settings();
    if (isset($input['content'])) {
      $input['content'] = Xss::filterAdmin((string) $input['content']);
    }
    if (isset($input['read_more_content'])) {
      $input['read_more_content'] = Xss::filterAdmin((string) $input['read_more_content']);
    }
    $next = ConsentSettings::sanitize($input, $this->defaultCss());
    $next['consent_version'] = (int) $current['consent_version'];
    $next['dismissed_catalog_ids'] = ConsentSettings::merge_dismissed(
      $current['cookies'],
      $next['cookies'],
      $next['dismissed_catalog_ids']
    );
    $this->write($next);
    return $this->view();
  }

  /**
   * Bumps the consent version.
   *
   * @return array<string, mixed>
   */
  public function renew(): array {
    $this->write(ConsentSettings::renew($this->settings()));
    return $this->view();
  }

  /**
   * Restores factory settings and bumps the consent version.
   *
   * @return array<string, mixed>
   */
  public function reset(): array {
    $this->write(ConsentSettings::reset($this->settings(), $this->defaultCss()));
    return $this->view();
  }

  /**
   * Restores the stylesheet only.
   *
   * @return array<string, mixed>
   */
  public function resetStyles(): array {
    $next = $this->settings();
    $next['custom_css'] = $this->defaultCss();
    $this->write($next);
    return $this->view();
  }

  /**
   * Adds catalog rows when this request shows a known cookie or library.
   * Writes state only when a new row appears.
   *
   * @param array<int, string> $cookieNames
   *   Cookie names.
   * @param array<int, string> $handles
   *   Drupal library ids.
   */
  public function detect(array $cookieNames, array $handles): void {
    $settings = $this->settings();
    $merged = ConsentCatalog::merge(
      $settings['cookies'],
      $settings['dismissed_catalog_ids'],
      $cookieNames,
      $handles
    );
    if (!$merged['changed']) {
      return;
    }
    $settings['cookies'] = $merged['rows'];
    $this->write($settings);
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  private function write(array $settings): void {
    $this->cache = $settings;
    $this->state->set(self::STATE_KEY, $settings);
    $this->assets->deleteAll();
    $this->pageCache?->deleteAll();
    $this->dynamicPageCache?->deleteAll();
    $this->tags->invalidateTags(['pendulum_arc:consent']);
  }

}
