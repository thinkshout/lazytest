<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'User' URLProvider.
 *
 * @URLProvider(
 *   id = "user",
 *   label = @Translation("(user) 10 newest and 10 oldest active users excluding user 0."),
 * )
 */
class UserURLProvider extends URLProviderBase {

  public function getURLs() {
    return $this->loadEntitiesAndCreateURLs(
      '',
      'user',
      [
        [
          'field' => 'uid',
          'value' => 0,
          'operator' => '>'
        ],
        [
          'field' => 'status',
          'value' => 1
        ]
      ],
      'uid',
      ['DESC', 'ASC',],
      10,
      'user',
      ''
    );
  }
}
