<?php

declare(strict_types=1);

namespace Drupal\omnipedia_warmer\Plugin\warmer;

use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Utility\Error;
use Drupal\node\NodeStorageInterface;
use Drupal\omnipedia_core\Entity\Node;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Drupal\warmer\Plugin\WarmerPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Omnipedia wiki node (CDN) cache warmer plug-in.
 *
 * @Warmer(
 *   id           = "omnipedia_wiki_node_cdn",
 *   label        = @Translation("Omnipedia: wiki pages (CDN)"),
 *   description  = @Translation("Executes HTTP requests to warm edge caches for anonymous users.")
 * )
 *
 * @todo Implement configurable HTTP headers?
 *
 * @see \Drupal\warmer\Plugin\WarmerInterface
 *   Documentation for public methods.
 *
 * @see \Drupal\warmer_cdn\Plugin\warmer\CdnWarmer
 *   Parts adapted from this plug-in shipped with the Warmer module.
 *
 * @see \Drupal\warmer_cdn\Plugin\warmer\UserInputParserTrait
 *   Can be used to parse and validate headers if/when we implement that.
 */
class WikiNodeCdnWarmer extends WarmerPluginBase {

  /**
   * The Drupal settings name to attempt to retrieve the host from.
   */
  protected const SETTINGS_NAME = 'primary_host';

  /**
   * The host name to rewrite node URLs to.
   *
   * @var string
   *
   * @see $this->rewriteUrl()
   */
  protected string $host;

  /**
   * An array of node IDs (nids) to warm, keyed by their revision IDs.
   *
   * @var array|null
   *   If this is null, that indicates that $this->getIdsToWarm() has not yet
   *   run. Once that runs once, this will be an array, either empty or
   *   populated with the results of the entity query.
   *
   * @see \Drupal\Core\Entity\Query\QueryInterface::execute()
   *   Uses the format returned by the entity query.
   */
  protected ?array $idsToWarm = null;

  /**
   * The Drupal account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected AccountSwitcherInterface $accountSwitcher;

  /**
   * The Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Our logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $loggerChannel;

  /**
   * The Drupal node entity storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * The Drupal user entity storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected UserStorageInterface $userStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration, $pluginId, $pluginDefinition
  ) {

    /** @var \Drupal\warmer\Plugin\WarmerInterface */
    $instance = parent::create(
      $container, $configuration, $pluginId, $pluginDefinition
    );

    /** @var \Drupal\Core\Site\Settings */
    $settings = $container->get('settings');

    // If the primary host setting is set, use that.
    if (!empty($settings->get(self::SETTINGS_NAME))) {
      $instance->setHost($settings->get(self::SETTINGS_NAME));

    // If not, set it to the host that Symfony says we're being requested from
    // as a fallback.
    } else {
      $instance->setHost(
        $container->get('request_stack')->getMainRequest()->getHttpHost()
      );
    }

    $instance->setAddtionalDependencies(
      $container->get('account_switcher'),
      $container->get('http_client'),
      $container->get('logger.channel.omnipedia_warmer'),
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('entity_type.manager')->getStorage('user')
    );

    return $instance;

  }

  /**
   * Set additional dependencies.
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The Drupal account switcher service.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Guzzle HTTP client.
   *
   * @param \Psr\Log\LoggerInterface $loggerChannel
   *   Our logger channel.
   *
   * @param \Drupal\node\NodeStorageInterface $nodeStorage
   *   The Drupal node entity storage.
   *
   * @param \Drupal\user\UserStorageInterface $userStorage
   *   The Drupal user entity storage.
   */
  public function setAddtionalDependencies(
    AccountSwitcherInterface  $accountSwitcher,
    ClientInterface           $httpClient,
    LoggerInterface           $loggerChannel,
    NodeStorageInterface      $nodeStorage,
    UserStorageInterface      $userStorage
  ): void {

    $this->accountSwitcher  = $accountSwitcher;
    $this->httpClient       = $httpClient;
    $this->loggerChannel    = $loggerChannel;
    $this->nodeStorage      = $nodeStorage;
    $this->userStorage      = $userStorage;

  }

  /**
   * Set the detected HTTP host.
   *
   * @param string $host
   *   The host, i.e. domain name, without the scheme or path.
   */
  public function setHost(string $host): void {
    $this->host = $host;
  }

  /**
   * Rewrite a node URL to force the use of our host.
   *
   * This is to work around an issue on DigitalOcean App Platform where the URL
   * can sometimes return a service's IP address or the oEmbed domain rather
   * than the primary domain.
   *
   * @param string $url
   *   The URL to rewrite.
   *
   * @return string
   *   The rewritten node URL.
   *
   * @todo Don't rewrite if primary host not explicitly set.
   */
  protected function rewriteUrl(string $url): string {

    /** @var array */
    $parsedUrl = \parse_url($url);

    $parsedUrl['host'] = $this->host;

    /** @var string The URL rewritten to use $this->host. */
    $rewrittenUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] .
      $parsedUrl['path'];

    if (!empty($parsedUrl['query'])) {
      $rewrittenUrl .= '?' . $parsedUrl['query'];
    }

    if (!empty($parsedUrl['fragment'])) {
      $rewrittenUrl .= '#' . $parsedUrl['fragment'];
    }

    return $rewrittenUrl;

  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {

    /** @var array */
    $config = parent::defaultConfiguration();

    // Reduce the batch size as some wiki nodes can take a few seconds.
    $config['batchSize'] = 5;

    $config['max_concurrent_requests'] = 2;

    $config['sleep_between_batches'] = 5;

    return $config;

  }

  /**
   * {@inheritdoc}
   */
  public function addMoreConfigurationFormElements(
    array $form, SubformStateInterface $formState
  ) {

    /** @var array */
    $config = $this->getConfiguration();

    $form['max_concurrent_requests'] = [
      '#type'           => 'number',
      '#min'            => 1,
      '#step'           => 1,
      '#title'          => $this->t('Maximum number of concurrent HTTP requests'),
      '#description'    => $this->t(
        '<p>The maximum number of requests to send in parallel.</p><p><em>Setting this value too high may result in denial-of-service protections being triggered at the host or reverse proxy level so care is advised.</em></p>'
      ),
      '#default_value'  => $config['max_concurrent_requests'],
    ];

    $form['sleep_between_batches'] = [
      '#type'           => 'number',
      '#min'            => 1,
      '#step'           => 1,
      '#title'          => $this->t('Sleep between batches'),
      '#description'    => $this->t(
        '<p>Time in seconds to sleep between batches.</p><p><em>Setting this value too low may result in denial-of-service protections being triggered at the host or reverse proxy level so care is advised.</em></p>'
      ),
      '#default_value'  => $config['sleep_between_batches'],
    ];

    $form['verify'] = [
      '#type'           => 'checkbox',
      '#title'          => $this->t('Enable HTTPS verification'),
      '#description'    => $this->t(
        '<p>Enable HTTPS verification.</p><p><em>It\'s recommended to keep this checked for security reasons and only intended for local testing with self-signed certificates.</em></p>'
      ),
      '#default_value'  => $config['verify'] ?? true,
    ];

    return $form;

  }

  /**
   * Get the IDs to warm, building the array if not already built.
   *
   * @return array
   *
   * @see $this->idsToWarm
   */
  protected function getIdsToWarm(): array {

    if (\is_array($this->idsToWarm)) {
      return $this->idsToWarm;
    }

    /** @var \Drupal\user\UserInterface The anonymous user entity. */
    $anonymousUser = $this->userStorage->load(0);

    // Switch to the anonymous user so that the node entity query only returns
    // nodes anonymous users have access to.
    $this->accountSwitcher->switchTo($anonymousUser);

    // This builds and executes a \Drupal\Core\Entity\Query\QueryInterface to
    // get all available wiki node IDs (nids).
    /** @var array */
    $queryResult = ($this->nodeStorage->getQuery())
      ->condition('type', Node::getWikiNodeType())
      // This will limit results to only nodes that the user has access to.
      ->accessCheck(true)
      ->execute();

    // Switch back to the previous user, if any.
    $this->accountSwitcher->switchBack();

    $this->idsToWarm = $queryResult;

    return $this->idsToWarm;

  }

  /**
   * {@inheritdoc}
   */
  public function buildIdsBatch($cursor) {

    $ids = $this->getIdsToWarm();

    /** @var int|false */
    $cursorPosition = \is_null($cursor) ? -1 :
      // Get the integer offset given the current cursor. Note that we have to
      // use \array_values($ids) to be able to + 1 increment the offset in
      // the \array_slice(), since that array uses revision IDs as keys.
      \array_search($cursor, \array_values($ids));

    // If \array_search() returned false, bail returning an empty array.
    if ($cursorPosition === false) {
      return [];
    }

    return \array_slice($ids, $cursorPosition + 1, (int) $this->getBatchSize());

  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = []) {

    /** @var array */
    $items = [];

    foreach ($ids as $revisionId => $nid) {

      /** \Drupal\omnipedia_core\Entity\NodeInterface|null */
      $node = $this->nodeStorage->load($nid);

      if (!\is_object($node)) {
        continue;
      }

      $items[$nid] = $node;

    }

    return $items;

  }

  /**
   * {@inheritdoc}
   */
  public function warmMultiple(array $items = []) {

    /** @var array */
    $config = $this->getConfiguration();

    $maxConcurrentRequests = (int) $config['max_concurrent_requests'];

    // Default to one request at a time.
    if ($maxConcurrentRequests <= 0) {
      $maxConcurrentRequests = 1;
    }

    // Not yet configurable.
    //
    // @see \Drupal\warmer_cdn\Plugin\warmer\UserInputParserTrait
    $headers = [];

    $verify = (bool) $config['verify'];

    /** @var \GuzzleHttp\Promise\PromiseInterface[] */
    $promises = [];

    /** @var integer The number of requests that were successfully sent and did not return an HTTP error code. */
    $count = 0;

    foreach ($items as $node) {

      /** @var string The absolute canonical URL for this node. */
      $url = $this->rewriteUrl((string) $node->toUrl(
        'canonical', ['absolute' => true]
      )->toString());

      // Fire off an async request to the node URL.
      $promises[] = $this->httpClient->requestAsync('GET', $url, [
        'headers' => $headers, 'verify' => $verify, 'connect_timeout' => 100,
      ])->then(function (ResponseInterface $response) use (&$count, $url) {
          if ($response->getStatusCode() < 399) {

            $count++;

            // $this->loggerChannel->debug(
            //   'Successfully requested <code>%url</code>, got response code <code>%code</code>.', [
            //     '%url'  => $url,
            //     '%code' => $response->getStatusCode(),
            //   ]
            // );

          }

        }, function (\Exception $exception) use ($url) {

          /** @var array */
          $context = Error::decodeException($exception);

          $context['%requestUrl'] = $url;

          // Log the exception.
          //
          // @see \watchdog_exception()
          //   We're replicating what this function does, but using the injected
          //   logger channel.
          $this->loggerChannel->error(
            '%type: @message in %function (line %line of %file).<br>Requested <code>%requestUrl</code>',
            $context
          );

        });

      // Wait for all requests if max number is reached.
      if (count($promises) >= $maxConcurrentRequests) {

        Utils::all($promises)->wait();

        $promises = [];

      }

    }

    // Wait for remaining requests to complete, if any.
    if (!empty($promises)) {
      Utils::all($promises)->wait();
    }

    // If a sleep value is configured, use it.
    //
    // @todo Is \sleep() good enough for this or should a more advanced solution
    //   be found?
    if ($config['sleep_between_batches'] > 0) {
      \sleep((int) $config['sleep_between_batches']);
    }

    return $count;

  }

}
