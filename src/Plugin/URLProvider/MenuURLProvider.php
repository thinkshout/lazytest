<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;

/**
 * Provides a 'Menu' URLProvider.
 *
 * @URLProvider(
 *   id = "menu",
 *   label = @Translation("(menu) All Menu items."),
 * )
 */
class MenuURLProvider extends URLProviderBase {

  public function getURLs() {
    return $this->loadEntitiesAndCreateURLs(
      '',
      'menu_link_content',
      [
        [
          'field' => 'enabled',
          'value' => 1
        ]
      ],
      '',
      [''],
      0,
      'menu',
      ''
    );
  }
}
