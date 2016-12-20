<?php

namespace Drupal\acquia_search\Plugin\SolrConnector;

use Drupal\Core\Url;
use Drupal\search_api_solr\Annotation\SolrConnector;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Form\FormStateInterface;
use Drupal\acquia_search\EventSubscriber\SearchSubscriber;
use Solarium\Client;

/**
 * Class SearchApiSolrAcquiaConnector.
 *
 * @package Drupal\acquia_search\Plugin\SolrConnector
 *
 * @SolrConnector(
 *   id = "solr_acquia_connector",
 *   label = @Translation("Acquia"),
 *   description = @Translation("Index items using an Acquia Apache Solr search server.")
 * )
 */
class SearchApiSolrAcquiaConnector extends SolrConnectorPluginBase {

  protected $eventDispatcher = FALSE;
  protected $preferredCoreService = FALSE;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();
    unset($configuration['host']);
    unset($configuration['port']);
    unset($configuration['path']);
    unset($configuration['core']);

    // Instantiate the coreService that will tell us the preferred core to use.
    $this->preferredCoreService = acquia_search_get_core_service();

    $disable_auto_switch = \Drupal::config('acquia_search.settings')->get('disable_auto_switch');

    // If the preferred core is available, use that.
    if (empty($disable_auto_switch) && $this->preferredCoreService->isPreferredCoreAvailable()) {
      // If we found a proper search core, specify that one as the target.
      $preferred_core_id = $this->preferredCoreService->getPreferredCoreId();
      $configuration['index_id'] = $preferred_core_id;
      $configuration['path'] = '/solr/' . $preferred_core_id;
      $configuration['host'] = $this->preferredCoreService->getPreferredCoreHostname();
      $configuration['port'] = '80';
      $configuration['overridden_by_acquia_search'] = ACQUIA_SEARCH_OVERRIDE_AUTO_SET;
    }
    else {
      // This means we can't detect which Index should be used, so we need to
      // protect it.

      // We enforce read-only mode in 2 ways:
      // * The module implements hook_search_api_index_load() and alters
      //   indexes' read-only flag.
      // * In this plugin, we "emulate" read-only mode by overriding
      //   $this->getUpdateQuery() and avoiding all updates just in case
      //   something is still attempting to directly call a Solr update.
      //
      // TODO: Evaluate other options to protect the index. Example: Connect to a fallback (local?) search index. The URL and key could be defined in settings. However, this could still allow easy polluting of production?

      // No proper search core found, therefore we fall back to the one named
      //   the same as the acquia_identifier.
      $acquia_identifier = \Drupal::config('acquia_connector.settings')->get('identifier');
      $configuration['index_id'] = $acquia_identifier;
      $configuration['path'] = '/solr/' . $acquia_identifier;
      $configuration['host'] = acquia_search_get_search_host();
      $configuration['port'] = '80';
      // Flag this server as "should have updates blocked" in getUpdateQuery()
      $configuration['overridden_by_acquia_search'] = ACQUIA_SEARCH_AUTO_OVERRIDE_READ_ONLY;
    }

    // Add any global Acquia Search connection overrides.
    // These apply to every Search API Server using this Solr connector.
    // @todo: Document the how overrides work.
    // @TODO: make sure the overrides don't make it permanently into the DB!
    $override = \Drupal::config('acquia_search.settings')->get('connection_override');
    if (!empty($override) && is_array($override)) {
      $configuration = array_merge($configuration, $override);
    }

    return $configuration;
  }

  /**
   * {@inheritdoc}
   *
   * Acquia-specific: 'admin/info/system' path is protected by Acquia.
   * Use admin/system instead.
   */
  public function pingServer() {
    return $this->doPing(['handler' => 'admin/system'], 'server');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    unset($form['host']);
    unset($form['port']);
    unset($form['path']);
    unset($form['core']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Turn off connection check of parent class.
  }

  /**
   * {@inheritdoc}
   */
  protected function connect() {
    if (!$this->solr) {
      $this->solr = new Client();
      $this->solr->createEndpoint($this->configuration + [
          'key' => 'core',
          'port' => ($this->configuration['scheme'] == 'https') ? 443 : 80,
        ], TRUE);
      $this->attachServerEndpoint();
      $this->eventDispatcher = $this->solr->getEventDispatcher();
      $plugin = new SearchSubscriber();
      $this->solr->registerPlugin('acquia_solr_search_subscriber', $plugin);
      // Don't use curl.
      $this->solr->setAdapter('Solarium\Core\Client\Adapter\Http');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getServerUri() {
    $this->connect();
    return $this->solr->getEndpoint('core')->getBaseUri();
  }

  /**
   * {@inheritdoc}
   *
   * Avoid providing an valid Update query if module determines this server
   *   should be locked down (as indicated by the overridden_by_acquia_search
   *   server option).
   */
  public function getUpdateQuery() {
    $this->connect();
    $overridden = $this->solr->getEndpoint('server')->getOption('overridden_by_acquia_search');
    if ($overridden == ACQUIA_SEARCH_AUTO_OVERRIDE_READ_ONLY) {
      $message = 'The Search API Server serving this index is currently in read-only mode.';
      \Drupal::logger('acquia search')->error($message);
      throw new \Exception($message);
      return null;
    }
    return $this->solr->createUpdate();
  }

  /**
   * {@inheritdoc}
   */
  public function getCoreLink() {
    return $this->getServerLink();
  }

  /**
   * {@inheritdoc}
   * @todo: check if we can move things from theme_ to here?
   */
  public function viewSettings() {
    $uri = Url::fromUri('http://www.acquia.com/products-services/acquia-search', array('absolute' => TRUE));
    drupal_set_message(t("Search is being provided by @as.", array('@as' => \Drupal::l(t('Acquia Search'), $uri))));
    return parent::viewSettings();
  }

}
