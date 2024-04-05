<?php

namespace Drupal\lazytest\Plugin;

use Drupal\Core\Url;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class URLProviderBase extends PluginBase implements URLProviderInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The route provider service.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a new URLProviderBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, RouteProviderInterface $route_provider) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->routeProvider = $route_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('router.route_provider')
    );
  }

  /**
   * Load entities of a given type.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The loaded entities.
   */
  protected function loadEntities($entity_type) {
    return $this->entityTypeManager->getStorage($entity_type)->loadMultiple();
  }

  /**
   * Execute a query on a given entity type.
   *
   * @param string $entity_type
   *   The entity type.
   * @param array $query
   *   The query array.
   *
   * @return array
   *   The result of the query.
   */
  protected function executeEntityQuery($entity_type, $query) {
    return $this->entityTypeManager->getStorage($entity_type)->getQuery()->execute($query);
  }

  /**
   * Load entities of a given type and create URLs from them.
   *
   * @param string $entity_type_id
   *   The entity type id, use to fetch entities for each bundle.
   * @param string $entity_type
   *   The entity type.
   * @param array $conditions
   *   The conditions for the query.
   * @param string $sort_field
   *   The field to sort by.
   * @param array $sort_orders
   *   The order to sort by. Either 'ASC' or 'DESC'.
   * @param int $range
   *   The number of entities to return.
   * @param string $source
   *   The source name.
   * @param string $subsource
   *   The sub source name.
   *
   * @return array
   *   An array of URLs.
   */
  protected function loadEntitiesAndCreateURLs(string $entity_type_id, string $entity_type, array $conditions, string $sort_field, array $sort_orders, int $range, string $source, string $subsource): array {
    $urls = [];
    $entity_bundles = empty($entity_type_id) ? [NULL] : \Drupal::entityTypeManager()->getStorage($entity_type_id)->loadMultiple();
    foreach ($entity_bundles as $entity_bundle) {
      $entity_bundle_id = $entity_bundle ? $entity_bundle->id() : '';
      foreach ($sort_orders as $sort_order) {
        $entity_ids = $this->executeEntityQueryWithConditions($entity_type, $entity_bundle_id, $conditions, $sort_field, $sort_order, $range);
        $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($entity_ids);
        foreach ($entities as $entity) {
          if ($subsource == '#entity_bundle') {
            $subsource_final = $entity_bundle_id;
          }
          else {
            $subsource_final = $subsource;
          }
          $urls[] = $this->createUrlFromEntity($entity, $source, $subsource_final);
        }
      }
    }
    return $urls;
  }

  /**
   * Create an absolute URL from a route.
   *
   * @param string $route_name
   *   The route name.
   * @param array $parameters
   *   The route parameters.
   * @param string $source
   *   The source name.
   * @param string $subsource
   *   The sub source name.
   *
   * @return array
   *   The array containing the URL and source information.
   */
  protected function createUrlFromRoute(string $route_name, array $parameters = [], string $source, string $subsource): array {
    $url_object = Url::fromRoute($route_name, $parameters);
    $url_object->setAbsolute();
    $url = $url_object->toString();
    return [
      'source' => $source,
      'subsource' => $subsource,
      'url' => $url,
    ];
  }

  /**
   * Creates an absolute URL from a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity from which to generate the URL.
   * @param string $source
   *   The source name.
   * @param string $subsource
   *   The sub source name.
   *
   * @return array
   *   An associative array containing the source, subsource, and the absolute URL.
   */
  protected function createUrlFromEntity(\Drupal\Core\Entity\EntityInterface $entity, string $source, string $subsource): array {
    if ($entity instanceof \Drupal\file\FileInterface) {
      $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager');
      $file_uri = $entity->getFileUri();
      $url = $stream_wrapper_manager->getViaUri($file_uri)->getExternalUrl();
    }
    elseif($entity instanceof \Drupal\views\Entity\View) {

      // Skip views that are part of Drupal core.
      if ($entity->get('module') !== 'views') {
        return [];
      }
      // We only want to generate URLs for views that have a page display.
      foreach ($entity->get('display') as $display) {
        if ($display['display_plugin'] === 'page' && (!isset($display['display_options']['enabled']) || (isset($display['display_options']['enabled']) && $display['display_options']['enabled'] !== FALSE))) {
          // The 'path' of the page display will be used as the URL.
          try {
            return $this->createUrlFromRoute('view.' . $entity->id() . '.' . $display['id'], [], 'views', $entity->id());
          } catch (\Exception $e) {
            return [];
            // If the route does not exist, ignore it.
          }
        }
      }
      return [];

    }
    else {
      $url_object = $entity->toUrl();
      $url_object->setAbsolute();
      $url = $url_object->toString();
    }

    return [
      'source' => $source,
      'subsource' => $subsource,
      'url' => $url,
    ];
  }

  /**
   * Executes a query on a given entity type with provided conditions, sort field, sort order and range.
   *
   * @param string $entity_type
   *   The entity type.
   * @param array $conditions
   *   The conditions for the query.
   * @param string $sort_field
   *   The field to sort by.
   * @param string $sort_order
   *   The order to sort by. Either 'ASC' or 'DESC'.
   * @param int $range
   *   The number of entities to return.
   *
   * @return array
   *   The result of the query.
   */
  protected function executeEntityQueryWithConditions(string $entity_type, string $entity_bundle, array $conditions, string $sort_field, string $sort_order, int $range): array {
    $query = $this->entityTypeManager->getStorage($entity_type)->getQuery()
      ->accessCheck(FALSE);

    if (!empty($sort_field) && !empty($sort_order)) {
      $query = $query->sort($sort_field, $sort_order);
    }

    if (!empty($range)) {
      $query = $query->range(0, $range);
    }

    foreach ($conditions as $condition) {
      if ($condition["value"] == "#entity_bundle") {
        $condition["value"] = $entity_bundle;
      }
      $query = $query->condition($condition['field'], $condition['value'], $condition['operator'] ?? '=');
    }

    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getURLs();

}
