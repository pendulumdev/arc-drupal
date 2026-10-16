<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Cookie consent settings: defaults, sanitizing, renew, and reset.
 */
final class ConsentSettings {

  /**
   * Nine overlay cells. The first word is the vertical edge.
   *
   * @return list<string>
   */
  public static function positions(): array {
    return [
      'top-left',
      'top-center',
      'top-right',
      'middle-left',
      'center',
      'middle-right',
      'bottom-left',
      'bottom-center',
      'bottom-right',
    ];
  }

  /**
   * Where the banner is printed. Fixed is an overlay. Static joins a region.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   */
  public static function print_slot(array $settings): string {
    if ('static' === ($settings['layout'] ?? '')) {
      return 'header' === ($settings['placement'] ?? '') ? 'header' : 'footer';
    }
    return 'fixed';
  }

  /**
   * Theme regions to try for a static banner, first match wins.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   *
   * @return list<string>
   */
  public static function region_candidates(array $settings): array {
    $slot = self::print_slot($settings);
    if ('header' === $slot) {
      return ['header', 'header_top', 'navbar'];
    }
    if ('footer' === $slot) {
      return ['footer', 'footer_bottom', 'footer_top'];
    }
    return [];
  }

  /**
   * Banner classes. Fixed uses the nine-cell overlay. Static is a bar.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   */
  public static function banner_class(array $settings): string {
    if ('static' === ($settings['layout'] ?? '')) {
      $place = 'header' === ($settings['placement'] ?? '') ? 'header' : 'footer';
      return 'arc-cookie arc-cookie--' . $place . ' arc-cookie--static';
    }
    return 'arc-cookie arc-cookie--fixed arc-cookie--pos-' . self::position($settings);
  }

  /**
   * Collapsed button classes. Same placement rule as the banner.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   */
  public static function reopen_class(array $settings): string {
    if ('static' === ($settings['layout'] ?? '')) {
      $place = 'header' === ($settings['placement'] ?? '') ? 'header' : 'footer';
      return 'arc-cookie__reopen arc-cookie--' . $place;
    }
    return 'arc-cookie__reopen arc-cookie--pos-' . self::position($settings);
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  private static function position(array $settings): string {
    $position = (string) ($settings['fixed_position'] ?? 'bottom-left');
    if (!in_array($position, self::positions(), TRUE)) {
      return 'bottom-left';
    }
    return $position;
  }

  /**
   * Factory settings.
   *
   * @param string $css
   *   Default stylesheet.
   *
   * @return array<string, mixed>
   */
  public static function defaults(string $css): array {
    return [
      'consent_version' => 1,
      'title' => 'Cookies on this site',
      'content' => 'We use cookies to run this site and, if you allow them, to remember your choices. Accept allows the optional cookies left checked. Read more lists them.',
      'read_more_content' => 'Required cookies are needed for the site to work and stay on. Optional cookies are set only if you leave them checked and click Accept. You can change this later from the cookie button.',
      'cookies' => [],
      'dismissed_catalog_ids' => [],
      'read_more' => 'modal',
      'read_more_url' => '',
      'show_cookie_table' => TRUE,
      'reload_accept' => FALSE,
      'reload_decline' => FALSE,
      'after_choice' => 'collapse',
      'logging_enabled' => FALSE,
      'disable_identifiable' => FALSE,
      'disabled' => FALSE,
      'layout' => 'fixed',
      'placement' => 'footer',
      'fixed_position' => 'bottom-left',
      'disable_styles' => FALSE,
      'accept_label' => 'Accept',
      'accept_colour' => '#1a9bb8',
      'decline_label' => 'Decline',
      'decline_colour' => '#6b7280',
      'read_more_label' => 'Read more',
      'read_more_colour' => '#6b7280',
      'reopen_icon_url' => '',
      'custom_css' => $css,
    ];
  }

  /**
   * A stored value merged onto the factory settings.
   *
   * @param mixed $stored
   *   Stored settings.
   * @param string $css
   *   Default stylesheet.
   *
   * @return array<string, mixed>
   */
  public static function normalize(mixed $stored, string $css): array {
    $defaults = self::defaults($css);
    if (!is_array($stored)) {
      return $defaults;
    }
    return self::sanitize(array_merge($defaults, $stored), $css);
  }

  /**
   * Cleans a settings payload. The consent version is clamped, not trusted
   * as a permission: the store keeps the saved version on an ordinary save.
   *
   * @param array<string, mixed> $input
   *   Incoming values.
   * @param string $css
   *   Default stylesheet.
   *
   * @return array<string, mixed>
   */
  public static function sanitize(array $input, string $css): array {
    $defaults = self::defaults($css);
    $cookies = [];
    $seen = [];
    $rows = isset($input['cookies']) && is_array($input['cookies']) ? $input['cookies'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $clean = self::cookie($row);
      if (NULL === $clean) {
        continue;
      }
      if (isset($seen[$clean['id']])) {
        $clean['id'] = self::new_id();
      }
      $seen[$clean['id']] = TRUE;
      $cookies[] = $clean;
      if (count($cookies) >= 200) {
        break;
      }
    }

    return [
      'consent_version' => self::version($input['consent_version'] ?? 1),
      'title' => self::plain($input['title'] ?? '', $defaults['title'], 200),
      'content' => self::markup($input['content'] ?? '', $defaults['content']),
      'read_more_content' => self::markup($input['read_more_content'] ?? '', $defaults['read_more_content']),
      'cookies' => $cookies,
      'dismissed_catalog_ids' => self::id_list($input['dismissed_catalog_ids'] ?? []),
      'read_more' => self::choice($input['read_more'] ?? '', ['modal', 'link', 'no'], 'modal'),
      'read_more_url' => self::link((string) ($input['read_more_url'] ?? '')),
      'show_cookie_table' => self::flag($input['show_cookie_table'] ?? TRUE),
      'reload_accept' => self::flag($input['reload_accept'] ?? FALSE),
      'reload_decline' => self::flag($input['reload_decline'] ?? FALSE),
      'after_choice' => self::choice($input['after_choice'] ?? '', ['hide', 'collapse', 'remain'], 'collapse'),
      'logging_enabled' => self::flag($input['logging_enabled'] ?? FALSE),
      'disable_identifiable' => self::flag($input['disable_identifiable'] ?? FALSE),
      'disabled' => self::flag($input['disabled'] ?? FALSE),
      'layout' => self::choice($input['layout'] ?? '', ['fixed', 'static'], 'fixed'),
      'placement' => self::choice($input['placement'] ?? '', ['header', 'footer'], 'footer'),
      'fixed_position' => self::choice($input['fixed_position'] ?? '', self::positions(), 'bottom-left'),
      'disable_styles' => self::flag($input['disable_styles'] ?? FALSE),
      'accept_label' => self::plain($input['accept_label'] ?? '', $defaults['accept_label'], 80),
      'accept_colour' => self::colour((string) ($input['accept_colour'] ?? ''), '#1a9bb8'),
      'decline_label' => self::plain($input['decline_label'] ?? '', $defaults['decline_label'], 80),
      'decline_colour' => self::colour((string) ($input['decline_colour'] ?? ''), '#6b7280'),
      'read_more_label' => self::plain($input['read_more_label'] ?? '', $defaults['read_more_label'], 80),
      'read_more_colour' => self::colour((string) ($input['read_more_colour'] ?? ''), '#6b7280'),
      'reopen_icon_url' => self::link((string) ($input['reopen_icon_url'] ?? '')),
      'custom_css' => self::css(array_key_exists('custom_css', $input) ? (string) $input['custom_css'] : $css),
    ];
  }

  /**
   * Asks every visitor again. Other settings stay.
   *
   * @param array<string, mixed> $settings
   *   Current settings.
   *
   * @return array<string, mixed>
   */
  public static function renew(array $settings): array {
    $settings['consent_version'] = self::version($settings['consent_version'] ?? 1) + 1;
    return $settings;
  }

  /**
   * Factory settings, with the version moved on so existing visitors are asked again.
   *
   * @param array<string, mixed> $settings
   *   Current settings.
   * @param string $css
   *   Default stylesheet.
   *
   * @return array<string, mixed>
   */
  public static function reset(array $settings, string $css): array {
    $next = self::defaults($css);
    $next['consent_version'] = self::version($settings['consent_version'] ?? 1) + 1;
    return $next;
  }

  /**
   * Catalog ids removed from the list stay dismissed. Ids that are present again are forgotten.
   *
   * @param array<int, array<string, mixed>> $previous
   *   Previous rows.
   * @param array<int, array<string, mixed>> $next
   *   Saved rows.
   * @param array<int, string> $dismissed
   *   Already dismissed ids.
   *
   * @return list<string>
   */
  public static function merge_dismissed(array $previous, array $next, array $dismissed): array {
    $gone = [];
    $kept = [];
    foreach ($previous as $row) {
      $id = isset($row['catalog_id']) ? (string) $row['catalog_id'] : '';
      if ('' !== $id) {
        $gone[$id] = TRUE;
      }
    }
    foreach ($next as $row) {
      $id = isset($row['catalog_id']) ? (string) $row['catalog_id'] : '';
      if ('' !== $id) {
        $kept[$id] = TRUE;
        unset($gone[$id]);
      }
    }
    foreach (array_keys($gone) as $id) {
      $dismissed[] = $id;
    }
    $out = [];
    foreach ($dismissed as $id) {
      $id = (string) $id;
      if ('' === $id || isset($kept[$id]) || isset($out[$id])) {
        continue;
      }
      $out[$id] = TRUE;
    }
    return array_keys($out);
  }

  /**
   * Hex colour, or the fallback when the value is not a colour.
   */
  public static function colour(string $value, string $fallback): string {
    $value = trim($value);
    if (1 !== preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
      return strtolower($fallback);
    }
    return strtolower($value);
  }

  /**
   * Site path or http(s) URL. Anything else is dropped.
   */
  public static function link(string $value): string {
    $value = trim($value);
    if ('' === $value) {
      return '';
    }
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
      return $value;
    }
    if (filter_var($value, FILTER_VALIDATE_URL) && 1 === preg_match('#^https?://#i', $value)) {
      return $value;
    }
    return '';
  }

  /**
   * @param array<string, mixed> $row
   *   Incoming cookie.
   *
   * @return array<string, string>|null
   */
  private static function cookie(array $row): ?array {
    $name = self::plain($row['name'] ?? '', '', 200);
    if ('' === $name) {
      return NULL;
    }
    $id = isset($row['id']) ? (string) $row['id'] : '';
    if (1 !== preg_match('/^[a-z0-9_]{1,64}$/', $id)) {
      $id = self::new_id();
    }
    $catalog = isset($row['catalog_id']) ? (string) $row['catalog_id'] : '';
    if (1 !== preg_match('/^[a-z0-9_]{0,64}$/', $catalog)) {
      $catalog = '';
    }
    return [
      'id' => $id,
      'catalog_id' => $catalog,
      'name' => $name,
      'label' => self::plain($row['label'] ?? '', $name, 200),
      'description' => self::plain($row['description'] ?? '', '', 2000),
      'requirement' => self::choice($row['requirement'] ?? '', ['optional', 'required'], 'optional'),
      'type' => self::plain($row['type'] ?? '', '', 80),
      'scripts' => self::scripts((string) ($row['scripts'] ?? '')),
      'match' => self::choice($row['match'] ?? '', ['exact', 'prefix'], 'exact'),
    ];
  }

  /**
   * Fresh row id.
   */
  private static function new_id(): string {
    return 'c_' . bin2hex(random_bytes(4));
  }

  /**
   * @param mixed $value
   *   Incoming version.
   */
  private static function version(mixed $value): int {
    $version = (int) $value;
    return $version > 0 ? $version : 1;
  }

  /**
   * @param mixed $value
   *   Incoming flag.
   */
  private static function flag(mixed $value): bool {
    return TRUE === $value || 1 === $value || '1' === $value || 'true' === $value;
  }

  /**
   * @param mixed $value
   *   Incoming value.
   * @param array<int, string> $allowed
   *   Allowed values.
   * @param string $fallback
   *   Fallback.
   */
  private static function choice(mixed $value, array $allowed, string $fallback): string {
    $value = (string) $value;
    return in_array($value, $allowed, TRUE) ? $value : $fallback;
  }

  /**
   * @param mixed $value
   *   Incoming text.
   * @param string $fallback
   *   Used when the text is empty.
   * @param int $limit
   *   Maximum length.
   */
  private static function plain(mixed $value, string $fallback, int $limit): string {
    $text = trim(self::strip(str_replace("\0", '', (string) $value)));
    if ('' === $text) {
      $text = $fallback;
    }
    if (strlen($text) > $limit) {
      $text = substr($text, 0, $limit);
    }
    return $text;
  }

  /**
   * @param mixed $value
   *   Incoming HTML.
   * @param string $fallback
   *   Used when the HTML is empty.
   */
  private static function markup(mixed $value, string $fallback): string {
    $text = str_replace("\0", '', (string) $value);
    if ('' === trim(self::strip($text))) {
      return $fallback;
    }
    if (strlen($text) > 50000) {
      $text = substr($text, 0, 50000);
    }
    return $text;
  }

  /**
   * @param string $css
   *   Incoming CSS.
   */
  private static function css(string $css): string {
    $css = str_replace("\0", '', $css);
    if (strlen($css) > 100000) {
      $css = substr($css, 0, 100000);
    }
    return self::upgrade_css($css);
  }

  /**
   * Older installs saved rem sizes. A 10px theme root shrank the card and
   * the cookie table, and theme table or heading rules could restyle them.
   */
  public static function upgrade_css(string $css): string {
    $css = str_replace(
      [
        'font-size: 0.875rem;',
        'font-size: 1rem;',
        'font-size: 0.8125rem;',
        'font-size: 0.75rem;',
      ],
      [
        'font-size: 16px;',
        'font-size: 18px;',
        'font-size: 14px;',
        'font-size: 13px;',
      ],
      $css
    );
    if (!str_contains($css, 'arc-cookie-layout-lock')
      && (str_contains($css, 'max-width: 40rem') || str_contains($css, 'max-width: 32rem'))) {
      $css .= "\n" . self::layout_lock();
    }
    if (str_contains($css, '.arc-cookie__choice') && !str_contains($css, 'arc-cookie-choice-size')) {
      $css .= "\n" . self::choice_size();
    }
    return $css;
  }

  /**
   * A 10px theme root made a 1rem checkbox a 10px box.
   */
  private static function choice_size(): string {
    return <<<'CSS'
/* arc-cookie-choice-size */
.arc-cookie-root input[type="checkbox"].arc-cookie__choice { box-sizing: border-box; width: 20px; height: 20px; min-width: 20px; min-height: 20px; margin: 2px 0 0; accent-color: #1a9bb8; font-size: 20px; }
CSS;
  }

  /**
   * Pixel sizes and theme overrides for a stylesheet that still uses rem.
   */
  private static function layout_lock(): string {
    return <<<'CSS'
/* arc-cookie-layout-lock */
.arc-cookie { max-width: 512px; margin: 16px; padding: 20px; border-radius: 24px; }
.arc-cookie-root h2.arc-cookie__title { margin: 0 0 8px; color: #12141a; font-family: inherit; font-size: 18px; font-weight: 650; letter-spacing: normal; text-transform: none; }
.arc-cookie__content, .arc-cookie__content p { font-family: inherit; font-size: 16px; letter-spacing: normal; text-transform: none; }
.arc-cookie__button { min-height: 36px; padding: 6px 16px; border-radius: 12px; font-family: inherit; font-size: 14px; letter-spacing: normal; text-transform: none; }
.arc-cookie__modal { z-index: 100001; }
.arc-cookie__dialog { max-width: 640px; max-height: min(80vh, 640px); padding: 24px; border-radius: 24px; }
.arc-cookie-root table.arc-cookie__table { table-layout: auto; width: 100%; margin: 16px 0 0; background: transparent; font-size: 14px; }
.arc-cookie__table th, .arc-cookie__table td { padding: 8px 12px; }
.arc-cookie__table th:first-child, .arc-cookie__table td:first-child, .arc-cookie__table th:last-child, .arc-cookie__table td:last-child { width: 1%; white-space: nowrap; }
.arc-cookie__table th:nth-child(2), .arc-cookie__table td:nth-child(2) { min-width: 200px; }
.arc-cookie__cookie { overflow-wrap: break-word; }
CSS;
  }

  /**
   * @param mixed $value
   *   Incoming id list.
   *
   * @return list<string>
   */
  private static function id_list(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $id) {
      $id = (string) $id;
      if (1 !== preg_match('/^[a-z0-9_]{1,64}$/', $id) || isset($out[$id])) {
        continue;
      }
      $out[$id] = TRUE;
      if (count($out) >= 200) {
        break;
      }
    }
    return array_keys($out);
  }

  /**
   * Removes tags. This class is loaded by PHPUnit before Drupal exists.
   */
  private static function strip(string $value): string {
    $stripped = preg_replace('/<[^>]*>/', '', $value);
    return is_string($stripped) ? $stripped : $value;
  }

  /**
   * One library id or URL per line.
   */
  private static function scripts(string $value): string {
    $value = str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $value);
    $kept = [];
    foreach (explode("\n", $value) as $line) {
      $line = trim(self::strip($line));
      if ('' === $line) {
        continue;
      }
      if (strlen($line) > 500) {
        $line = substr($line, 0, 500);
      }
      $kept[] = $line;
      if (count($kept) >= 50) {
        break;
      }
    }
    $text = implode("\n", $kept);
    if (strlen($text) > 5000) {
      return substr($text, 0, 5000);
    }
    return $text;
  }

}
