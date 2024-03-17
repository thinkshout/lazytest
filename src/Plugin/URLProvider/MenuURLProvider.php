<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;

/**
 * Provides a 'Menu' URLProvider.
 *
 * @URLProvider(
 *   id = "menu_url_provider",
 *   label = @Translation("Menu"),
 * )
 */
class MenuURLProvider extends URLProviderBase {

  public function getURLs() {
    $urls = [];
    $menus = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadMultiple();

    foreach ($menus as $menu) {
      if ($menu->isEnabled()) {
        $link = $menu->get('link')->first()->getUrl();
        $link->setAbsolute();
        $urls[] = [
          'source' => "menu",
          'subsource' => $menu->label(),
          'url' => $link->toString(),
        ];
      }
    }

    return $urls;
  }

}
