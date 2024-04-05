<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'Views' URLProvider.
 *
 * @URLProvider(
 *   id = "views",
 *   label = @Translation("(views) All published Views with a page display."),
 * )
 */
class ViewsURLProvider extends URLProviderBase {

  public function getURLs() {
    // There's some views specific code in createUrlFromEntity as well.
    return $this->loadEntitiesAndCreateURLs(
      '',
      'view',
      [
        [
          'field' => 'status',
          'value' => 1
        ]
      ],
      '',
      [''],
      0,
      'views',
      ''
    );
  }
}
