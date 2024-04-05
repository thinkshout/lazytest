<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'File' URLProvider.
 *
 * @URLProvider(
 *   id = "file",
 *   label = @Translation("(file) 10 newest and 10 oldest File Entities."),
 * )
 */
class FileURLProvider extends URLProviderBase {

  public function getURLs() {
    return $this->loadEntitiesAndCreateURLs(
      '',
      'file',
      [
        [
          'field' => 'status',
          'value' => 1
        ]
      ],
      'fid',
      ['DESC', 'ASC',],
      10,
      'file',
      ''
    );
  }
}
