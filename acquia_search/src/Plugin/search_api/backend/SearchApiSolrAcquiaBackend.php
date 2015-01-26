<?php

namespace Drupal\acquia_search\Plugin\search_api\backend;

use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\ServerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\acquia_search\EventSubscriber\SearchSubscriber;


/**
 * @SearchApiBackend(
 *   id = "search_api_solr_acquia",
 *   label = @Translation("Acquia Solr"),
 *   description = @Translation("Index items using an Acquia Apache Solr search server.")
 * )
 */
class SearchApiSolrAcquiaBackend extends SearchApiSolrBackend {

  protected $eventDispatcher = FALSE;
  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, FormBuilderInterface $form_builder, ModuleHandlerInterface $module_handler, Config $search_api_solr_settings) {
    // @todo Research right way to add default configuration.
    $configuration['port'] = 80; // @todo - add to settings
    $configuration['path'] = '/solr/' . \Drupal::config('acquia_connector.settings')->get('identifier'); // @todo use acquia_search.settings
    $configuration['solr_version'] = 4; // @todo - add to settings

    $subscription = $configuration['host'] = \Drupal::config('acquia_connector.settings')->get('subscription_data');
    $search_host = \Drupal::config('acquia_search.settings')->get('host');
    // Adding the subscription specific colony to the heartbeat data
    if (!empty($subscription['heartbeat_data']['search_service_colony'])) {
      $search_host = $subscription['heartbeat_data']['search_service_colony'];
    }
    // Check if we are on Acquia Cloud hosting. @see NN-2503
    if (!empty($_ENV['AH_SITE_ENVIRONMENT']) && !empty($_ENV['AH_CURRENT_REGION'])) {
      if ($_ENV['AH_CURRENT_REGION'] == 'us-east-1' && $search_host == 'search.acquia.com') {
        $search_host = 'internal-search.acquia.com';
      }
      elseif (strpos($search_host, 'search-' . $_ENV['AH_CURRENT_REGION']) === 0) {
        $search_host = 'internal-' . $search_host;
      }
    }
    $configuration['host'] = $search_host;

    return parent::__construct($configuration, $plugin_id, $plugin_definition, $form_builder, $module_handler, $search_api_solr_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('module_handler'),
      $container->get('config.factory')->get('search_api_solr.settings')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getServer() {
    return parent::getServer();
  }

  /**
   * {@inheritdoc}
   */
  public function setServer(ServerInterface $server) {
    parent::setServer($server);
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $uri = Url::fromUri('http://www.acquia.com/products-services/acquia-search', array('absolute' => TRUE));
    drupal_set_message(t("Search is being provided by the !as network service.", array('!as' => \Drupal::l(t('Acquia Search'), $uri))));
    return parent::viewSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function supportsFeature($feature) {
    return parent::supportsFeature($feature);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDatatype($type) {
    return parent::supportsDatatype($type);
  }

  /**
   * {@inheritdoc}
   */
  public function postInsert() {
    return parent::postInsert();
  }

  /**
   * {@inheritdoc}
   */
  public function preUpdate() {
    return parent::preUpdate();
  }

  /**
   * {@inheritdoc}
   */
  public function postUpdate() {
    return parent::postUpdate();
  }

  /**
   * {@inheritdoc}
   */
  public function preDelete() {
    return parent::preDelete();
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    return parent::addIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    return parent::updateIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    return parent::removeIndex($index);
  }

  public function deleteAllIndexItems(IndexInterface $index) {
    return parent::deleteAllIndexItems($index);
  }

  public function indexItems(IndexInterface $index, array $items) {
    return parent::indexItems($index, $items);
  }
  public function deleteItems(IndexInterface $index, array $item_ids) {
    return parent::deleteAllIndexItems($index, $item_ids);
  }

  public function search(QueryInterface $query) {
    return parent::search($query);
  }

  /**
   * Creates a connection to the Solr server as configured in $this->configuration.
   */
  protected function connect() {
    parent::connect();
    // @todo: Solarium uses different event dispatcher (shipped with search_api_solr by composer update). Do something with it.
    if (!$this->eventDispatcher) {
      $this->eventDispatcher = $this->solr->getEventDispatcher();
      $plugin = new SearchSubscriber();
      $this->solr->registerPlugin('acquia_solr_search_subscriber', $plugin);
      // Don't user curl.
      $this->solr->setAdapter('Solarium\Core\Client\Adapter\Http');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['host']['#disabled'] = TRUE;
    $form['port']['#disabled'] = TRUE;
    $form['path']['#disabled'] = TRUE;
    $form['advanced']['solr_version']['#disabled'] = TRUE;

    return $form;
  }

}
