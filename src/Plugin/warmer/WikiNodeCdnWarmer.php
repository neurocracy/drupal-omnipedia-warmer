<?php

declare(strict_types=1);

namespace Drupal\omnipedia_warmer\Plugin\warmer;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Site\MaintenanceModeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Utility\Error;
use Drupal\omnipedia_core\Entity\Node;
use Drupal\omnipedia_core\Service\WikiNodeAccessInterface;
use Drupal\user\UserInterface;
use Drupal\warmer\Plugin\WarmerPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
 *
 * @see https://api.drupal.org/api/drupal/core%21modules%21node%21node.module/group/node_access
 *   API documentation on limitations of node access checking with entity
 *   queries which explains that they don't check published status.
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
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The Drupal account switcher service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The Drupal entity type manager.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Guzzle HTTP client.
   *
   * @param \Psr\Log\LoggerInterface $loggerChannel
   *   Our logger channel.
   *
   * @param \Drupal\Core\Site\MaintenanceModeInterface $maintenanceMode
   *   The Drupal maintenance mode service.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The Symfony request stack.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The Drupal site settings.
   *
   * @param \Drupal\Core\State\StateInterface
   *   The Drupal state service.
   *
   * @param \Drupal\Component\Datetime\TimeInterface
   *   The Drupal time service.
   *
   * @param \Drupal\omnipedia_core\Service\WikiNodeAccessInterface $wikiNodeAccess
   *   The Omnipedia wiki node access service.
   */
  public function __construct(
    array $configuration, $pluginId, $pluginDefinition,
    protected readonly AccountSwitcherInterface   $accountSwitcher,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ClientInterface            $httpClient,
    protected readonly LoggerInterface            $loggerChannel,
    protected readonly MaintenanceModeInterface   $maintenanceMode,
    RequestStack    $requestStack,
    Settings        $settings,
    StateInterface  $state,
    TimeInterface   $time,
    protected readonly WikiNodeAccessInterface $wikiNodeAccess
  ) {

    parent::__construct(
      $configuration, $pluginId, $pluginDefinition, $state, $time
    );

    // If the primary host setting is set, use that.
    if (!empty($settings->get(self::SETTINGS_NAME))) {
      $this->setHost($settings->get(self::SETTINGS_NAME));

    // If not, set it to the host that Symfony says we're being requested from
    // as a fallback.
    } else {
      $this->setHost(
        $requestStack->getMainRequest()->getHttpHost()
      );
    }

  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration, $pluginId, $pluginDefinition
  ) {

    // This replicates what WarmerPluginBase::create() does so that we don't
    // need to call parent::create() in order to use PHP 8 constructor property
    // promotion for all our dependencies.
    $warmersConfig = $container->get('config.factory')
      ->get('warmer.settings')
      ->get('warmers');

    $pluginSettings = empty(
      $warmersConfig[$pluginId]
    ) ? [] : $warmersConfig[$pluginId];

    $configuration = \array_merge($pluginSettings, $configuration);

    return new static(
      $configuration, $pluginId, $pluginDefinition,
      $container->get('account_switcher'),
      $container->get('entity_type.manager'),
      $container->get('http_client'),
      $container->get('logger.channel.omnipedia_warmer'),
      $container->get('maintenance_mode'),
      $container->get('request_stack'),
      $container->get('settings'),
      $container->get('state'),
      $container->get('datetime.time'),
      $container->get('omnipedia.wiki_node_access')
    );

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
    $anonymousUser = $this->entityTypeManager->getStorage('user')->load(0);

    // Return an empty array if the anonymous role does not have permissions to
    // access content to begin with. This is necessary because the entity query
    // does not seem to take this into account.
    if (!$this->wikiNodeAccess->canUserAccessAnyWikiNode($anonymousUser)) {
      return [];
    }

    // If maintenance mode is enabled and the anonymous user is not exempt,
    // return an empty array both because they won't be able to access the nodes
    // and also to avoid caching the responses at both the Drupal and reverse
    // proxy levels.
    if (
      $this->state->get('system.maintenance_mode') &&
      !$this->maintenanceMode->exempt($anonymousUser)
    ) {
      return [];
    }

    // Switch to the anonymous user so that the node entity query only returns
    // nodes anonymous users have access to.
    $this->accountSwitcher->switchTo($anonymousUser);

    /** @var \Drupal\Core\Entity\Query\QueryInterface */
    $query = ($this->entityTypeManager->getStorage('node')->getQuery())
      ->condition('type', Node::getWikiNodeType())
      // This should limit results to only nodes that the user has access to but
      // in practice doesn't fully check access due to the significant
      // performance overhead.
      //
      // @see https://api.drupal.org/api/drupal/core%21modules%21node%21node.module/group/node_access
      ->accessCheck(true)
      // Generally speaking, an anonymous user is very unlikely to have
      // permission to view unpublished nodes, so exclude those. This needs to
      // be here as the access check above does not take this into account.
      ->condition('status', 1);

    /** @var array */
    $queryResult = $query->execute();

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

    /** @var \Drupal\user\UserInterface The anonymous user entity. */
    $anonymousUser = $this->entityTypeManager->getStorage('user')->load(0);

    /** @var array */
    $items = [];

    foreach ($ids as $revisionId => $nid) {

      /** \Drupal\omnipedia_core\Entity\NodeInterface|null */
      $node = $this->entityTypeManager->getStorage('node')->load($nid);

      if (
        !\is_object($node) ||
        // Perform one last direct access check here to make sure.
        !$node->access('view', $anonymousUser)
      ) {
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
