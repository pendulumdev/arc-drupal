<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\pendulum_arc\Rules\ConsentSettings;

/**
 * Escaped cookie banner markup. The same HTML is used for every visitor.
 */
final class ConsentMarkup {

  use StringTranslationTrait;

  /**
   * Banner, reopen button, and read-more modal.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   * @param string $iconUrl
   *   Reopen icon URL.
   */
  public function banner(array $settings, string $iconUrl): string {
    $title = (string) $settings['title'];
    $style = sprintf(
      '--arc-cookie-accept:%1$s;--arc-cookie-decline:%2$s;--arc-cookie-more:%3$s;',
      Html::escape((string) $settings['accept_colour']),
      Html::escape((string) $settings['decline_colour']),
      Html::escape((string) $settings['read_more_colour'])
    );
    $html = '<div class="arc-cookie-root" style="' . Html::escape($style) . '" data-arc-cookie-root>';
    $html .= '<div class="' . Html::escape(ConsentSettings::banner_class($settings)) . '" data-arc-cookie-banner role="region" aria-labelledby="arc-cookie-title">';
    $html .= '<h2 id="arc-cookie-title" class="arc-cookie__title">' . Html::escape($title) . '</h2>';
    $html .= '<div class="arc-cookie__content">' . $this->content($settings) . '</div>';
    $html .= '<div class="arc-cookie__actions">' . $this->actions($settings) . '</div>';
    $html .= '</div>';
    $html .= $this->reopen($settings, $title, $iconUrl);
    $html .= $this->modal($settings, $title);
    $html .= '</div>';
    return $html;
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  public function title(array $settings): string {
    return Html::escape((string) $settings['title']);
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  public function content(array $settings): string {
    return Xss::filterAdmin((string) $settings['content']);
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  public function readMore(array $settings): string {
    return Xss::filterAdmin((string) $settings['read_more_content']);
  }

  /**
   * @param array<int, array<string, mixed>> $rows
   *   Cookie rows.
   */
  public function table(array $rows): string {
    if ($rows === []) {
      return '';
    }
    $html = '<table class="arc-cookie__table"><thead><tr>';
    $html .= '<th scope="col">' . Html::escape((string) $this->t('Choice')) . '</th>';
    $html .= '<th scope="col">' . Html::escape((string) $this->t('Cookie')) . '</th>';
    $html .= '<th scope="col">' . Html::escape((string) $this->t('About')) . '</th>';
    $html .= '<th scope="col">' . Html::escape((string) $this->t('Type')) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $optional = 'optional' === ($row['requirement'] ?? '');
      $html .= '<tr>';
      $html .= '<td>';
      if ($optional) {
        $html .= '<input class="arc-cookie__choice" type="checkbox" checked data-arc-cookie-choice="' . Html::escape((string) $row['id']) . '" aria-label="' . Html::escape((string) $row['label']) . '" />';
      }
      else {
        $html .= Html::escape((string) $this->t('Required'));
      }
      $html .= '</td>';
      $html .= '<td><span class="arc-cookie__name">' . Html::escape((string) $row['label']) . '</span>';
      $html .= '<span class="arc-cookie__cookie">' . Html::escape((string) $row['name']) . '</span></td>';
      $html .= '<td>' . Html::escape((string) ($row['description'] ?? '')) . '</td>';
      $html .= '<td>' . Html::escape((string) ($row['type'] ?? '')) . '</td>';
      $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
  }

  /**
   * Accept, decline, or read-more control.
   *
   * @param string $action
   *   accept, decline, or read-more.
   * @param array<string, mixed> $settings
   *   Settings.
   * @param string $label
   *   Button label. Empty uses the saved label.
   * @param string $colour
   *   Hex colour. Empty uses the saved colour.
   * @param string $href
   *   When set, the control is a link.
   */
  public function choiceButton(string $action, array $settings, string $label, string $colour, string $href = ''): string {
    $fields = [
      'accept' => ['accept_label', 'accept_colour', 'accept', '--arc-cookie-accept'],
      'decline' => ['decline_label', 'decline_colour', 'decline', '--arc-cookie-decline'],
      'read-more' => ['read_more_label', 'read_more_colour', 'more', '--arc-cookie-more'],
    ];
    if (!isset($fields[$action])) {
      return '';
    }
    $field = $fields[$action];
    $label = trim(strip_tags($label));
    if ($label === '') {
      $label = (string) $settings[$field[0]];
    }
    $colour = ConsentSettings::colour($colour, (string) $settings[$field[1]]);
    return $this->button($action, $label, $field[2], $field[3], $colour, $href);
  }

  /**
   * Read-more shortcode. Empty when read more is off or the link has no URL.
   *
   * @param array<string, mixed> $settings
   *   Settings.
   * @param string $label
   *   Optional label.
   * @param string $colour
   *   Optional colour.
   */
  public function readMoreButton(array $settings, string $label, string $colour): string {
    if ('no' === $settings['read_more']) {
      return '';
    }
    if ('link' === $settings['read_more']) {
      $url = (string) $settings['read_more_url'];
      if ($url === '') {
        return '';
      }
      return $this->choiceButton('read-more', $settings, $label, $colour, $url);
    }
    return $this->choiceButton('read-more', $settings, $label, $colour);
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   * @param string $title
   *   Banner title.
   * @param string $iconUrl
   *   Icon URL.
   */
  private function reopen(array $settings, string $title, string $iconUrl): string {
    $class = ConsentSettings::reopen_class($settings);
    return '<button type="button" class="' . Html::escape($class) . '" data-arc-cookie-reopen hidden aria-label="' . Html::escape($title) . '">'
      . '<img src="' . Html::escape($iconUrl) . '" alt="" width="22" height="22" />'
      . '</button>';
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   * @param string $title
   *   Dialog title.
   */
  private function modal(array $settings, string $title): string {
    if ('modal' !== $settings['read_more']) {
      return '';
    }
    $html = '<div class="arc-cookie__modal" data-arc-cookie-modal hidden>';
    $html .= '<button type="button" class="arc-cookie__backdrop" data-arc-cookie-action="close" aria-label="' . Html::escape((string) $this->t('Close')) . '"></button>';
    $html .= '<div class="arc-cookie__dialog" role="dialog" aria-modal="true" aria-labelledby="arc-cookie-more-title">';
    $html .= '<h2 id="arc-cookie-more-title" class="arc-cookie__title">' . Html::escape($title) . '</h2>';
    $html .= '<div class="arc-cookie__content">' . $this->readMore($settings) . '</div>';
    if (!empty($settings['show_cookie_table'])) {
      $cookies = isset($settings['cookies']) && is_array($settings['cookies']) ? $settings['cookies'] : [];
      $html .= $this->table($cookies);
    }
    $html .= '<div class="arc-cookie__actions">';
    $html .= $this->button('accept', (string) $settings['accept_label'], 'accept', '--arc-cookie-accept', (string) $settings['accept_colour']);
    $html .= $this->button('decline', (string) $settings['decline_label'], 'decline', '--arc-cookie-decline', (string) $settings['decline_colour']);
    $html .= '</div></div></div>';
    return $html;
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  private function actions(array $settings): string {
    $html = $this->button('accept', (string) $settings['accept_label'], 'accept', '--arc-cookie-accept', (string) $settings['accept_colour']);
    $html .= $this->button('decline', (string) $settings['decline_label'], 'decline', '--arc-cookie-decline', (string) $settings['decline_colour']);
    $html .= $this->readMoreControl($settings);
    return $html;
  }

  /**
   * @param array<string, mixed> $settings
   *   Settings.
   */
  private function readMoreControl(array $settings): string {
    $label = (string) $settings['read_more_label'];
    $colour = (string) $settings['read_more_colour'];
    if ('modal' === $settings['read_more']) {
      return $this->button('read-more', $label, 'more', '--arc-cookie-more', $colour);
    }
    if ('link' === $settings['read_more'] && (string) $settings['read_more_url'] !== '') {
      return $this->button('read-more', $label, 'more', '--arc-cookie-more', $colour, (string) $settings['read_more_url']);
    }
    return '';
  }

  /**
   * @param string $action
   *   accept, decline, or read-more.
   * @param string $label
   *   Button label.
   * @param string $variant
   *   accept, decline, or more.
   * @param string $variable
   *   CSS variable name.
   * @param string $colour
   *   Hex colour.
   * @param string $href
   *   When set, the control is a link.
   */
  private function button(string $action, string $label, string $variant, string $variable, string $colour, string $href = ''): string {
    $class = 'arc-cookie__button arc-cookie__button--' . $variant;
    $style = $variable . ':' . $colour;
    if ($href !== '') {
      return '<a class="' . Html::escape($class) . '" style="' . Html::escape($style) . '" href="' . Html::escape($href) . '">' . Html::escape($label) . '</a>';
    }
    return '<button type="button" class="' . Html::escape($class) . '" style="' . Html::escape($style) . '" data-arc-cookie-action="' . Html::escape($action) . '">' . Html::escape($label) . '</button>';
  }

}
