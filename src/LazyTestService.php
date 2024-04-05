<?php

namespace Drupal\lazytest;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderManager;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Helper\ProgressBar;


class LazyTestService {

  protected $urlProviderManager;

  public function __construct(URLProviderManager $urlProviderManager) {
    $this->urlProviderManager = $urlProviderManager;
  }

  public function getAllURLs($command_line_urls, $plugins) {
    $output = new ConsoleOutput();
    $output->writeln("Creating list of urls and starting download.");

    $allURLs = [];

    // Add urls passed in through the command line.
    if (!empty($command_line_urls)) {
      $command_line_urls = explode(',', $command_line_urls);
      foreach ($command_line_urls as $command_line_url) {
        $allURLs[] = [
          'source' => "command line",
          'url' => $command_line_url,
        ];
      }
    }

    // Use all plugins or just the one(s) coming in from the command line.
    if (empty($plugins)) {
      $definitions = $this->urlProviderManager->getDefinitions();
      foreach ($definitions as $definition) {
        $instance = $this->urlProviderManager->createInstance($definition['id']);
        $allURLs = array_merge($allURLs, $instance->getURLs());
      }
    }
    elseif ($plugins == "none") {
      // Do nothing.
    }
    else {
      $plugins = explode(',', $plugins);
      foreach ($plugins as $plugin) {
        $instance = $this->urlProviderManager->createInstance($plugin);
        $allURLs = array_merge($allURLs, $instance->getURLs());
      }
    }



    return $allURLs;
  }

  public function checkURLs($baseurl, $urls, $crawl, $crawldepth) {

    // We want to get fresh versions of pages.
    drupal_flush_all_caches();

    $output = new ConsoleOutput();
    $completedRequests = 0;

    $messages = [];

    foreach ($urls as &$url) {
      $url["url"] = $this->normalizeUrl($url["url"]);
    }
    unset($url);

    $allUrls = array_column($urls, 'url');

    $client = new Client([
      'base_uri' => $baseurl,
      'verify' => false, // Ignore SSL certificate errors
      'defaults' => [
        'headers' => [
          'Connection' => 'keep-alive',
        ],
      ],
    ]);
    $jar = new CookieJar;

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

    $startTimestamp = time();

    $layers = [$urls];  // Initialize layers with the first layer being $urls
    for ($i = 0; $i <= $crawldepth; $i++) {
      $layers[] = [];  // Initialize the next layer

      // Start a new progress bar for each level
      $progressBar = new ProgressBar($output, count($layers[$i]));
      $progressBar->start();

      $promises = function () use (&$layers, $client, $session_cookie, $i, &$allUrls, $baseurl, $crawl, $crawldepth) {
        foreach ($layers[$i] as $index => $url) {
          yield $index => function () use (&$layers, $client, $url, $session_cookie, $i, &$allUrls, $baseurl, $crawl, $crawldepth) {
            $requestPromise = $client->requestAsync('GET', $url["url"], [
              'headers' => [
                'Cookie' => $session_cookie,
              ],
              'timeout' => 120,
              'allow_redirects' => [
                'max' => 20,
                // follow up to x redirects
                'strict' => FALSE,
                // use strict RFC compliant redirects
                'referer' => TRUE,
                // add a Referer header
                'protocols' => ['http', 'https'],
                // restrict redirects to 'http' and 'https'
              ],
            ]);
            // We only need to get links if we're crawling and have not reached the crawl depth.
            if ($crawl && $i < $crawldepth) {
              $requestPromise = $requestPromise->then(function ($response) use (&$layers, $url, $i, &$allUrls, $baseurl, $crawl) {
                $html = $response->getBody();
                $dom = new \DOMDocument;
                @$dom->loadHTML($html);

                // If we're logged in, we get a lot of link options from the administrative toolbar. Leave those out.
                $xpath = new \DOMXPath($dom);
                $toolbarAdministrationDiv = $xpath->query('//div[@id="toolbar-administration"]')->item(0);
                if ($toolbarAdministrationDiv) {
                  $toolbarAdministrationDiv->parentNode->removeChild($toolbarAdministrationDiv);
                }

                $links = $dom->getElementsByTagName('a');
                foreach ($links as $link) {
                  $href = $link->getAttribute('href');
                  $absoluteUrl = $this->createAbsoluteUrlIfInternal($href, $url["url"], $baseurl);
                  // If $absoluteUrl is not null and it's not already in $allUrls, add it to the next layer and $allUrls
                  if ($absoluteUrl !== null && !in_array($absoluteUrl, $allUrls)) {
                    $layers[$i + 1][] = [
                      'source' => 'crawl',
                      'url' => $absoluteUrl,
                    ];
                    $allUrls[] = $absoluteUrl;
                  }
                }
                return $response;
              });
            }
            return $requestPromise;
          };
        }
      };

      $pool = new Pool($client, $promises(), [
        'concurrency' => 8,
        'fulfilled' => function ($response, $index) use ($output, $layers, $i, $startTimestamp, &$messages, &$completedRequests, $progressBar) {
          if (empty($response)) {
            $code = 'no response';
          }
          else {
            $code = $response->getStatusCode();
          }
          $url = $layers[$i][$index];
          $log_messages = $this->getLogMessages($url, $startTimestamp);
          if (!empty($log_messages) || $code != 200) {
            $message = [
              'source' => $url["source"],
              'subsource' => $url["subsource"] ?? '',
              'code' => $code,
              'url' => $url["url"],
              'message' => $log_messages,
            ];
            $messages[] = $message;
          }
          // Update the progress bar
          $completedRequests++;
          $progressBar->setProgress($completedRequests);
        },
        'rejected' => function ($reason, $index) use ($output, $layers, $i, $startTimestamp, &$messages, &$completedRequests, $progressBar) {
          $code = $reason->getCode();
          $url = $layers[$i][$index];
          $log_messages = $this->getLogMessages($url, $startTimestamp);
          $message = [
            'source' => $url["source"],
            'subsource' => $url["subsource"] ?? '',
            'code' => $code,
            'url' => $url["url"],
            'message' => $log_messages,
          ];
          $messages[] = $message;
          // Update the progress bar
          $completedRequests++;
          $progressBar->setProgress($completedRequests);
        },
      ]);

      // Initiate the transfers and create a promise
      $promise = $pool->promise();

      // Force the pool of requests to complete.
      $promise->wait();

      // Finish the progress bar for the current level
      $progressBar->finish();
      echo "\n";
    }

    // End the user session
    \Drupal::service('session_manager')->destroy();

    // Sort
    $source = array_column($messages, 'source');
    $subsource = array_column($messages, 'subsource');
    $url = array_column($messages, 'url');
    array_multisort($source, SORT_ASC, $subsource, SORT_ASC, $url, SORT_ASC, $messages);

    if (!empty($messages)) {
      $output->writeln("\n\nDone. Copy this into a csv file and import into a spreadsheet like Google Sheets.\n");
      $messagesCsv = $this->getLogMessagesAsCsv($messages);
      $output->writeln($messagesCsv);
      $output->writeln("\nAnalysis:\n---------\n");
      $this->getLogMessagesAnalysis($messages);
    }
    else {
      $output->writeln("\n\nDone. No issues found.\n");
    }

  }

  public function createAbsoluteUrlIfInternal($href, $currentUrl, $baseurl) {
    // If $href is an empty string, ignore.
    if (empty($href)) {
      return null;
    }

    // Exclude parameter only links
    if (strpos($href, '?') === 0) {
      return null;
    }

    // Exclude anchor links
    if (strpos($href, '#') === 0) {
      return null;
    }

    // Exclude URLs that contain ":" but not part of "http:" or "https:"
    if (strpos($href, ':') !== false && !preg_match('/https?:/', $href)) {
      return null;
    }

    // Normalize URL
    $href = $this->normalizeUrl($href);

    // Check if the URL is already absolute
    if (filter_var($href, FILTER_VALIDATE_URL)) {
      $absoluteUrl = $href;
    } else {
      // Convert relative URLs to absolute
      $absoluteUrl = $this->createAbsoluteUrl($href, $currentUrl, $baseurl);
    }

    // Check if the URL is an internal link
    $parsedAbsoluteUrl = parse_url($absoluteUrl);
    $baseHost = parse_url($baseurl, PHP_URL_HOST);
    if (isset($parsedAbsoluteUrl['host']) && $parsedAbsoluteUrl['host'] !== $baseHost) {
      return null;
    }

    // Exclude non-HTML links
    $extension = '';
    if (isset($parsedAbsoluteUrl['path'])) {
      $extension = pathinfo($parsedAbsoluteUrl['path'], PATHINFO_EXTENSION);
    }
    if ($extension != '' && $extension != 'html') {
      return null;
    }

    return $absoluteUrl;
  }

  public function normalizeUrl($url) {
    // Remove URL parameters
    $url = strtok($url, '?');
    // Convert to lowercase
    $url = strtolower($url);
    // Remove trailing slash only if the URL is not "/"
    if ($url !== '/') {
      $url = rtrim($url, '/');
    }
    return $url;
  }

  private function createAbsoluteUrl($relativeUrl, $currentUrl, $baseurl) {
    if ($relativeUrl === '/') {
      // If the relative URL is "/", return the base URL
      return $baseurl;
    } else if (strpos($relativeUrl, '/') === 0) {
      return Url::fromUri($baseurl . $relativeUrl)->setAbsolute()->toString();
    } else if (filter_var($relativeUrl, FILTER_VALIDATE_URL)) {
      // If the relative URL is already an absolute URL, return it as is
      return $relativeUrl;
    } else {
      return Url::fromUri($baseurl . '/' . $currentUrl . '/' . $relativeUrl)->setAbsolute()->toString();
    }
  }

  public function getLogMessagesAnalysis($messages) {
    $consolidatedErrors = [];

    foreach ($messages as $result) {
      foreach ($result['message'] as $message) {
        // Create a unique key for each error type based on only message for now.
        $errorKey = $message['message'];

        // Check if this error type has already been encountered
        if (!array_key_exists($errorKey, $consolidatedErrors)) {
          // If new, initialize the structure to hold error details and the first URL
          $consolidatedErrors[$errorKey] = [
            'source' => $result['source'],
            'code' => $result['code'],
            'message' => $message['message'],
            'urls' => [$result['url']],
            'count' => 1,
          ];
        } else {
          // If the error already exists, just append the URL to the list of affected URLs
          // This also checks to avoid duplicate URLs for the same error
          $consolidatedErrors[$errorKey]['count']++;  // Increment count
          if (!in_array($result['url'], $consolidatedErrors[$errorKey]['urls'])) {
            $consolidatedErrors[$errorKey]['urls'][] = $result['url'];
          }
        }
      }
    }

    // Output or further process the $consolidatedErrors array
    // For simplicity, the following code just prints the errors and associated URLs
    foreach ($consolidatedErrors as $error) {
      echo "Error count: " .  $error['count'] . " / Error Message: " . $error['message'] . "\n";
      echo "Affected URLs:\n";
      foreach ($error['urls'] as $url) {
        echo " - HTTP Status code: " . $error['code'] . " / Source: " . $error['source'] . " / " . $url . "\n";
      }
      echo "\n\n";
    }
  }

  public function getLogMessagesAsCsv($messages) {

    // Create a variable to hold your CSV data
    $row_array = [
      'source',
      'subsource',
      'http status code',
      'url',
      'message (type, severity, message)',
    ];
    $csvData = '"' . implode('","', $row_array) . "\"\n";

    // Loop through each item in your array
    foreach ($messages as $row) {

      $message_array = [];
      foreach ($row['message'] as $message) {
        $message_array[] = $message['type'] . ' - ' . $message['severity'] . ' - ' . $message['message'];
      }
      $message_string = implode('","',$message_array);

      $row_array = [
        $row['source'],
        $row['subsource'] ?? '',
        $row['code'],
        $row['url'],
        $message_string,
      ];

      // For each item, implode the array values with a comma to create a CSV row
      $csvData .= '"' . implode('","', $row_array) . "\"\n";

    }

    // Output the CSV data
    return $csvData;
  }

  public function getLogMessages($url, $startTimestamp) {
    $query = \Drupal::database()->select('watchdog', 'w');
    $query->fields('w', ['message', 'variables', 'type', 'location', 'severity']);
    $query->condition('w.timestamp', $startTimestamp, '>=');
    $query->condition('w.location', $url["url"], '=');
    $query->condition('w.severity', RfcLogLevel::WARNING, '>=');
    $query->orderBy('w.wid', 'DESC');
    $result = $query->execute()->fetchAll();
    $log_messages = [];
    foreach ($result as $record) {
      // Don't include backtrace or path.
      $message = str_replace('@backtrace_string.', '[backtrace filtered]', $record->message);
      $message = str_replace('@uri', '[uri filtered]', $message);
      $message = str_replace('%file_uri', '[file_uri filtered]', $message);
      $message = str_replace('@uuid', '[uuid filtered]', $message);
      $message = str_replace('@url', '[url filtered]', $message);
      $message = trim($message);
      $message = (string) t($message, unserialize($record->variables));
      $message = strip_tags($message);
      $log_messages[] = [
        'type' => $record->type,
        'severity' => $record->severity,
        'message' => $message,
      ];
    }

    return $log_messages;
  }

}
