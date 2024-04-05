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
    $urls = [];

    // Get the file storage service.
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');

    // Get oldest and newest.
    $sorts = [
      'DESC',
      'ASC',
    ];

    foreach ($sorts as $sort) {

      // Query for up to 10 random file entities.
      $file_ids = $file_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('fid', $sort)
        ->range(0, 10)
        ->execute();

      // Load the file entities.
      $files = $file_storage->loadMultiple($file_ids);

      // Generate URLs for each file entity.
      foreach ($files as $file) {
        $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager');
        $file_uri = $file->getFileUri();
        $url = $stream_wrapper_manager->getViaUri($file_uri)->getExternalUrl();
        $urls[] = [
          'source' => "file",
          'subsource' => '',
          'url' => $url,
        ];
      }
    }



    return $urls;
  }


}
