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

    foreach ($media_types as $media_type) {
      // Get media type id.
      $media_type_id = $media_type->id();

      // Query for up to 10 random media entities of the media type.
      $media_ids = $media_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $media_type_id)
        ->range(0, 10)
        ->addTag('sort_by_random')
        ->execute();

      // Load the media entities.
      $media_entities = $media_storage->loadMultiple($media_ids);

      // Generate URLs for each media entity.
      foreach ($media_entities as $media_entity) {
        if ($media_type_id === 'image' && $media_entity->hasField('field_media_image') && !$media_entity->get('field_media_image')->isEmpty()) {
          $file_uri = $media_entity->get('field_media_image')->entity->getFileUri();
        }
        elseif ($media_type_id === 'video' && $media_entity->hasField('field_media_video_file') && !$media_entity->get('field_media_video_file')->isEmpty()) {
          $file_uri = $media_entity->get('field_media_video_file')->entity->getFileUri();
        }
        else {
          continue;
        }

        $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager');
        $url = $stream_wrapper_manager->getViaUri($file_uri)->getExternalUrl();
        $urls[] = $url;
      }

    }

    return $urls;
  }

}
