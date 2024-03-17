<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'User' URLProvider.
 *
 * @URLProvider(
 *   id = "user_url_provider",
 *   label = @Translation("User"),
 * )
 */
class UserURLProvider extends URLProviderBase {

  public function getURLs() {
    $urls = [];

    // Get the user storage service.
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');

    // Get oldest and newest.
    $sorts = [
      'DESC',
      'ASC',
    ];

    foreach ($sorts as $sort) {

      $user_ids = $user_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', 0, '>')
        ->condition('status', 1)
        ->sort('uid', $sort)
        ->range(0, 10)
        ->execute();

      // Load the user entities.
      $users = $user_storage->loadMultiple($user_ids);

      // Generate URLs for each user entity.
      foreach ($users as $user) {
        // Get the user's URL.
        $url_object = $user->toUrl();
        $url_object->setAbsolute();
        $urls[] = [
          'source' => "user",
          'url' => $url_object->toString(),
        ];
      }
    }

    return $urls;
  }


}
