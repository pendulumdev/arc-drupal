<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Component\Utility\Html;
use Drupal\Core\Asset\AttachedAssetsInterface;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\pendulum_arc\Rules\ConsentScripts;
use Drupal\pendulum_arc\Rules\ConsentSettings;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prints the banner and marks optional libraries inert.
 *
 * The HTML does not vary by the visitor, so a page cache stays valid.
 * The front script reads arc_cookie_consent and turns allowed tags back
 * into scripts.
 */
final class ConsentFront {

  public const COOKIE = 'arc_cookie_consent';

  /**
   * Whether this request already placed the banner.
   */
  private bool $placed = FALSE;

  public function __construct(
    private readonly ConsentStore $store,
    private readonly ConsentMarkup $markup,
    private readonly RequestStack $requests,
    private readonly AdminContext $admin,
    private readonly LibraryDiscoveryInterface $libraries,
    private readonly ThemeManagerInterface $themes,
    private readonly FileUrlGeneratorInterface $files,
    private readonly ModuleHandlerInterface $modules,
  ) {}

  /**
   * Fixed banners, and a static banner when the theme has no matching region.
   *
   * @param array<string, mixed> $pageBottom
   *   page_bottom render array.
   */
  public function pageBottom(array &$pageBottom): void {
    if ($this->placed || !$this->visible()) {
      return;
    }
    $settings = $this->store->settings();
    $slot = ConsentSettings::print_slot($settings);
    if ($slot === 'fixed' || ($slot === 'footer' && !$this->hasRegion($settings))) {
      $pageBottom['pendulum_arc_consent'] = $this->banner($settings);
      $this->placed = TRUE;
    }
  }

  /**
   * Static header when the theme has no header region.
   *
   * @param array<string, mixed> $pageTop
   *   page_top render array.
   */
  public function pageTop(array &$pageTop): void {
    if ($this->placed || !$this->visible()) {
      return;
    }
    $settings = $this->store->settings();
    if (ConsentSettings::print_slot($settings) !== 'header' || $this->hasRegion($settings)) {
      return;
    }
    $pageTop['pendulum_arc_consent'] = $this->banner($settings);
    $this->placed = TRUE;
  }

  /**
   * Appends a static banner to the first matching region.
   *
   * @param array<string, mixed> $variables
   *   Page template variables.
   */
  public function placeInRegion(array &$variables): void {
    if ($this->placed || !$this->visible()) {
      return;
    }
    $settings = $this->store->settings();
    $slot = ConsentSettings::print_slot($settings);
    if ($slot !== 'header' && $slot !== 'footer') {
      return;
    }
    if (!isset($variables['page']) || !is_array($variables['page'])) {
      return;
    }
    $regions = $this->themeRegions();
    foreach (ConsentSettings::region_candidates($settings) as $region) {
      if (!in_array($region, $regions, TRUE)) {
        continue;
      }
      $variables['page'][$region]['pendulum_arc_consent'] = $this->banner($settings);
      $this->placed = TRUE;
      return;
    }
  }

  /**
   * Marks matching optional libraries as text/plain and records new catalog rows.
   *
   * Uses the saved settings, never the visitor cookie, so cached HTML stays
   * one version.
   *
   * @param array<string, mixed> $javascript
   *   JavaScript assets.
   * @param \Drupal\Core\Asset\AttachedAssetsInterface $assets
   *   Assets for this page.
   */
  public function alterJavascript(array &$javascript, AttachedAssetsInterface $assets): void {
    if ($this->isAdmin() || $this->suppressed()) {
      return;
    }
    $request = $this->requests->getCurrentRequest();
    $names = $request === NULL ? [] : ConsentStore::cookieNames($request);
    $map = $this->libraryMap($assets);
    $this->store->detect($names, array_values(array_unique(array_values($map))));
    $cookies = $this->store->settings()['cookies'];
    if (!is_array($cookies)) {
      return;
    }
    foreach ($javascript as &$item) {
      if (!is_array($item)) {
        continue;
      }
      $type = (string) ($item['type'] ?? '');
      if ($type !== 'file' && $type !== 'external') {
        continue;
      }
      $src = (string) ($item['data'] ?? '');
      $library = $map[$src] ?? '';
      $tag = '<script src="' . Html::escape($src) . '"></script>';
      $rewritten = ConsentScripts::rewrite_tag($tag, $library, $src, $cookies, []);
      if ($rewritten === $tag || 1 !== preg_match('/data-arc-cookie="([^"]*)"/', $rewritten, $matches)) {
        continue;
      }
      $item['preprocess'] = FALSE;
      if (!isset($item['attributes']) || !is_array($item['attributes'])) {
        $item['attributes'] = [];
      }
      $item['attributes']['type'] = 'text/plain';
      $item['attributes']['data-arc-script-type'] = 'text/javascript';
      $item['attributes']['data-arc-cookie'] = $matches[1];
    }
    unset($item);
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   *
   * @return array<string, mixed>
   */
  public function banner(array $settings): array {
    $build = [
      '#markup' => Markup::create($this->markup->banner($settings, $this->iconUrl($settings))),
      '#attached' => [
        'html_head' => [],
      ],
      '#cache' => [
        'tags' => ['pendulum_arc:consent'],
        'contexts' => ['languages:language_interface', 'url.site'],
        'max-age' => Cache::PERMANENT,
      ],
    ];
    $style = $this->styleElement($settings);
    if ($style !== NULL) {
      $build['#attached']['html_head'][] = [$style, 'pendulum_arc_consent_css'];
    }
    $script = $this->scriptElement($settings);
    if ($script !== NULL) {
      $build['#attached']['html_head'][] = [$script, 'pendulum_arc_consent_js'];
    }
    return $build;
  }

  /**
   * Public URL of the reopen icon. Empty stored URL uses the shipped SVG.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   */
  public function iconUrl(array $settings): string {
    $stored = (string) ($settings['reopen_icon_url'] ?? '');
    if ($stored !== '') {
      return $stored;
    }
    return $this->assetUrl('cookie-icon.svg');
  }

  /**
   * Module asset URL.
   */
  public function assetUrl(string $file): string {
    $path = $this->modules->getModule('pendulum_arc')->getPath() . '/assets/' . $file;
    return $this->files->generateString($path);
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   *
   * @return array<string, mixed>|null
   */
  private function styleElement(array $settings): ?array {
    if (!empty($settings['disable_styles'])) {
      return NULL;
    }
    $css = str_ireplace('</style', '<\/style', (string) $settings['custom_css']);
    if (trim($css) === '') {
      return NULL;
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'style',
      '#value' => Markup::create($css),
      '#attributes' => [
        'id' => 'pendulum-arc-consent-css',
      ],
    ];
  }

  /**
   * Inline behaviour, so a blocked script URL cannot leave the buttons dead.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   *
   * @return array<string, mixed>|null
   */
  private function scriptElement(array $settings): ?array {
    $path = $this->modules->getModule('pendulum_arc')->getPath() . '/assets/arc-consent.js';
    if (!is_readable($path)) {
      return NULL;
    }
    $js = file_get_contents($path);
    if (!is_string($js) || trim($js) === '') {
      return NULL;
    }
    $js = str_ireplace('</script', '<\/script', $js);
    $config = json_encode($this->config($settings), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($config)) {
      return NULL;
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#value' => Markup::create('var arcCookieConsent = ' . $config . ";\n" . $js),
      '#attributes' => [
        'id' => 'pendulum-arc-consent-js',
      ],
    ];
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   *
   * @return array<string, mixed>
   */
  private function config(array $settings): array {
    $cookies = [];
    $rows = isset($settings['cookies']) && is_array($settings['cookies']) ? $settings['cookies'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $cookies[] = [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'match' => (string) $row['match'],
        'requirement' => (string) $row['requirement'],
      ];
    }
    $request = $this->requests->getCurrentRequest();
    return [
      'version' => (int) $settings['consent_version'],
      'cookieName' => self::COOKIE,
      'maxAge' => 180 * 86400,
      'secure' => $request !== NULL && $request->isSecure(),
      'cookies' => $cookies,
      'reloadAccept' => (bool) $settings['reload_accept'],
      'reloadDecline' => (bool) $settings['reload_decline'],
      'afterChoice' => (string) $settings['after_choice'],
      'logging' => (bool) $settings['logging_enabled'],
      'logUrl' => Url::fromRoute('pendulum_arc.consent_log', [], ['absolute' => TRUE])->toString(),
    ];
  }

  /**
   * File path to Drupal library id for the libraries on this page.
   *
   * @param \Drupal\Core\Asset\AttachedAssetsInterface $assets
   *   Assets for this page.
   *
   * @return array<string, string>
   */
  private function libraryMap(AttachedAssetsInterface $assets): array {
    $map = [];
    foreach ($assets->getLibraries() as $library) {
      if (!is_string($library) || !str_contains($library, '/')) {
        continue;
      }
      [$extension, $name] = explode('/', $library, 2);
      try {
        $definition = $this->libraries->getLibraryByName($extension, $name);
      }
      catch (\Exception) {
        continue;
      }
      if (!is_array($definition) || empty($definition['js']) || !is_array($definition['js'])) {
        continue;
      }
      foreach ($definition['js'] as $key => $options) {
        if (is_string($options) && $options !== '') {
          $map[$options] = $library;
          continue;
        }
        if (!is_array($options)) {
          continue;
        }
        $data = (string) ($options['data'] ?? (is_string($key) ? $key : ''));
        if ($data !== '') {
          $map[$data] = $library;
        }
      }
    }
    return $map;
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  private function hasRegion(array $settings): bool {
    $regions = $this->themeRegions();
    foreach (ConsentSettings::region_candidates($settings) as $region) {
      if (in_array($region, $regions, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return list<string>
   */
  private function themeRegions(): array {
    return array_values($this->themes->getActiveTheme()->getRegions());
  }

  /**
   * Disable leaves the page and its script tags unchanged.
   */
  private function suppressed(): bool {
    return !empty($this->store->settings()['disabled']);
  }

  /**
   * The banner is a front-page element.
   */
  private function visible(): bool {
    if ($this->suppressed() || $this->isAdmin()) {
      return FALSE;
    }
    $request = $this->requests->getCurrentRequest();
    if ($request === NULL || $request->isXmlHttpRequest()) {
      return FALSE;
    }
    return $request->getRequestFormat() === 'html';
  }

  /**
   * Admin routes, including this module's screens.
   */
  private function isAdmin(): bool {
    try {
      return $this->admin->isAdminRoute();
    }
    catch (\Exception) {
      return FALSE;
    }
  }

}
