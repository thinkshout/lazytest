<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'Media' URLProvider.
 *
 * @URLProvider(
 *   id = "media_url_provider",
 *   label = @Translation("Media"),
 * )
 */
class MediaURLProvider extends URLProviderBase {

  public function getURLs() {
    $urls = [];

    // Get the media storage service.
    $media_storage = \Drupal::entityTypeManager()->getStorage('media');

    // Get all media type IDs.
    $media_types = \Drupal::entityTypeManager()
      ->getStorage('media_type')
      ->loadMultiple();

    // Get oldest and newest.
    $sorts = [
      'DESC',
      'ASC',
    ];

    foreach ($media_types as $media_type) {

      foreach ($sorts as $sort) {

        // Get media type id.
        $media_type_id = $media_type->id();

        // Query for up to 10 random media entities of the media type.
        $media_ids = $media_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 1)
          ->condition('bundle', $media_type_id)
          ->sort('mid', $sort)
          ->range(0, 10)
          ->execute();

        // Load the media entities.
        $media_entities = $media_storage->loadMultiple($media_ids);

        // Generate URLs for each media entity.
        foreach ($media_entities as $media_entity) {
          $url_object = Url::fromRoute('entity.media.canonical', ['media' => $media_entity->id()]);
          $url_object->setAbsolute();
          $url = $url_object->toString();
          $urls[] = [
            'source' => "media",
            'subsource' => $media_type_id,
            'url' => $url,
          ];
        }

      }


    }

    return $urls;
  }

}
