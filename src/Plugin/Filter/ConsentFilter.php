<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\pendulum_arc\ConsentMarkup;
use Drupal\pendulum_arc\ConsentStore;
use Drupal\pendulum_arc\Rules\ConsentList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Replaces arc_cookie tags. The banner itself is rendered by PHP.
 */
#[Filter(
  id: 'pendulum_arc_consent',
  title: new TranslatableMarkup('Arc Analytics cookie tags'),
  description: new TranslatableMarkup('Replaces arc_cookie tags with the cookie banner pieces.'),
  type: FilterInterface::TYPE_MARKUP_LANGUAGE,
)]
final class ConsentFilter extends FilterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConsentStore $store,
    private readonly ConsentMarkup $markup,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('pendulum_arc.consent_store'),
      $container->get('pendulum_arc.consent_markup'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($this->replace((string) $text));
    $result->addCacheTags(['pendulum_arc:consent']);
    return $result;
  }

  /**
   * Replaces one tag at a time so attributes stay with that tag.
   */
  private function replace(string $text): string {
    $settings = $this->store->settings();
    $text = str_replace('[arc_cookie_title]', $this->markup->title($settings), $text);
    $text = str_replace('[arc_cookie_content]', $this->markup->content($settings), $text);
    $text = str_replace('[arc_cookie_read_more]', $this->markup->readMore($settings), $text);
    $text = preg_replace_callback(
      '/\[arc_cookie_list(?:\s+([^\]]*))?\]/',
      function (array $matches) use ($settings): string {
        $atts = self::attributes($matches[1] ?? '');
        $rows = isset($settings['cookies']) && is_array($settings['cookies']) ? $settings['cookies'] : [];
        $filtered = ConsentList::filter($rows, (string) ($atts['optional'] ?? ''), (string) ($atts['type'] ?? ''));
        return $this->markup->table($filtered);
      },
      $text
    );
    $text = is_string($text) ? $text : '';
    foreach (['accept', 'decline'] as $action) {
      $text = preg_replace_callback(
        '/\[arc_cookie_' . $action . '(?:\s+([^\]]*))?\]/',
        function (array $matches) use ($settings, $action): string {
          $atts = self::attributes($matches[1] ?? '');
          return $this->markup->choiceButton(
            $action,
            $settings,
            (string) ($atts['label'] ?? ''),
            (string) ($atts['colour'] ?? '')
          );
        },
        $text
      );
      $text = is_string($text) ? $text : '';
    }
    $replaced = preg_replace_callback(
      '/\[arc_cookie_read_more_button(?:\s+([^\]]*))?\]/',
      function (array $matches) use ($settings): string {
        $atts = self::attributes($matches[1] ?? '');
        return $this->markup->readMoreButton(
          $settings,
          (string) ($atts['label'] ?? ''),
          (string) ($atts['colour'] ?? '')
        );
      },
      $text
    );
    return is_string($replaced) ? $replaced : $text;
  }

  /**
   * @return array<string, string>
   */
  private static function attributes(string $raw): array {
    $out = [];
    if (preg_match_all('/([a-z_]+)\s*=\s*"([^"]*)"/i', $raw, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $out[strtolower($match[1])] = $match[2];
      }
    }
    return $out;
  }

}
