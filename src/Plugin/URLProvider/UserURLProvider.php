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

    // Query for up to 10 random user entities.
    $user_ids = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->range(0, 10)
      ->addTag('sort_by_random')
      ->execute();

    // Load the user entities.
    $users = $user_storage->loadMultiple($user_ids);

    // Generate URLs for each user entity.
    foreach ($users as $user) {
      // Get the user's URL.
      $url_object = $user->toUrl();
      $url_object->setAbsolute();
      $urls[] = $url_object->toString();
    }

    return $urls;
  }


}
