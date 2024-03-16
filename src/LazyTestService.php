<?php

namespace Drupal\lazytest;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderManager;
use Drupal\Core\Database\Database;
use Drupal\Core\Logger\RfcLogLevel;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Console\Output\ConsoleOutput;



class LazyTestService {

  protected $urlProviderManager;

  public function __construct(URLProviderManager $urlProviderManager) {
    $this->urlProviderManager = $urlProviderManager;
  }

  public function getAllURLs() {
    $allURLs = [];
    $definitions = $this->urlProviderManager->getDefinitions();
    foreach ($definitions as $definition) {
      $instance = $this->urlProviderManager->createInstance($definition['id']);
      $allURLs = array_merge($allURLs, $instance->getURLs());
    }
    return $allURLs;
  }

  public function checkURLs($urls) {

    $output = new ConsoleOutput();

    // override url for debugging.
//    $urls = [];
//    $urls[] = "http://web.lvhn.localhost/admin/content";
//    $urls[] = "http://web.lvhn.localhost/adminfoo/content";
//    $urls[] = "http://web.lvhn.localhost/admin/structure/views/add";

    $client = new Client(['base_uri' => 'http://web.lvhn.localhost/']);
    $jar = new CookieJar;
    $errors = [];

    // Create a one-time login link for user 1
    $user = \Drupal\user\Entity\User::load(1);
    $timestamp = \Drupal::time()->getRequestTime();
    $link = Url::fromRoute(
      'user.reset.login',
      [
        'uid' => $user->id(),
        'timestamp' => $timestamp,
        'hash' => user_pass_rehash($user, $timestamp),
      ],
    )->toString();

    // Use the one-time login link and get the cookies.
    $client->request('GET', $link, ['cookies' => $jar]);
    $cookies = $jar->toArray();

    // Extract the session cookie name and value
    $session_cookie = '';
    foreach ($cookies as $cookie) {
      if (strpos($cookie['Name'], 'SESS') === 0) {
        $session_cookie = $cookie['Name'] . '=' . $cookie['Value'];
        break;
      }
    }

    $promises = function () use ($urls, $client, $session_cookie) {
      foreach ($urls as $url) {
        yield function() use ($client, $url, $session_cookie) {
          return $client->getAsync($url, [
            'headers' => [
              'Cookie' => $session_cookie,
            ],
          ]);
        };
      }
    };

    $pool = new Pool($client, $promises(), [
      'concurrency' => 100,
      'fulfilled' => function ($response, $index) use (&$errors, $output, $urls) {
        $url = $urls[$index];
        $code = $response->getStatusCode();
        $output->writeln("$code - $url");
        if ($code >= 500) {
          $errors[] = ['url' => $url, 'code' => $code];
        }
      },
      'rejected' => function ($reason, $url) use (&$errors, $output) {
        $url = (string) $reason->getRequest()->getUri();
        $code = $reason->getCode();
        $errors[] = ['url' => $url, 'code' => $code];
        $output->writeln("$code - $url");
      },
    ]);

    // Initiate the transfers and create a promise
    $promise = $pool->promise();

    // Force the pool of requests to complete.
    $promise->wait();

    // End the session when you're done
    \Drupal::service('session_manager')->destroy();

    return $errors;
  }

}
