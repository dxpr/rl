<?php

namespace Drupal\rl_example_frontend\Plugin\Block;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a newsletter signup block with A/B tested button text.
 *
 * Companion to the rl_example block. Both decide the winning variant
 * server-side and track turns / rewards through the Drupal.rl JS API.
 * The distinction is how conversions are recorded:
 *   - rl_example records the reward in a Drupal AJAX submit callback,
 *     which round-trips through a full Drupal request.
 *   - rl_example_frontend records the reward with Drupal.rl.reward() from
 *     a click handler, which goes through the thin rl.php endpoint and
 *     does not block the submit flow.
 *
 * @Block(
 *   id = "rl_example_frontend_newsletter",
 *   admin_label = @Translation("RL Example Frontend Newsletter Signup"),
 * )
 */
class NewsletterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected $experimentManager;

  /**
   * The RL experiment registry.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected $experimentRegistry;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The RL cache manager.
   *
   * @var \Drupal\rl\Service\CacheManager
   */
  protected $cacheManager;

  /**
   * The experiment ID.
   *
   * @var string
   */
  protected $experimentId;

  /**
   * The button text variations to test.
   *
   * @var array
   */
  protected $buttonTexts = [
    'subscribe' => 'Subscribe to Newsletter',
    'updates' => 'Get Weekly Updates',
    'notify' => 'Keep Me Informed',
  ];

  /**
   * Constructs a NewsletterBlock object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ExperimentManagerInterface $experiment_manager,
    ExperimentRegistryInterface $experiment_registry,
    MessengerInterface $messenger,
    CacheManager $cache_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->experimentManager = $experiment_manager;
    $this->experimentRegistry = $experiment_registry;
    $this->messenger = $messenger;
    $this->cacheManager = $cache_manager;

    // Use deterministic ID for this specific experiment.
    $this->experimentId = 'rl_example_frontend-newsletter_button';

    // Register our experiment.
    $this->experimentRegistry->register(
      $this->experimentId,
      'rl_example_frontend',
      'Frontend Newsletter Button A/B Test'
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    // @phpstan-ignore new.static
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('rl.experiment_manager'),
      $container->get('rl.experiment_registry'),
      $container->get('messenger'),
      $container->get('rl.cache_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Decide which variant to render in PHP. The arm list is owned right
    // here in the block config so the rl core never needs to know it.
    $scores = $this->experimentManager->getThompsonScores(
      $this->experimentId,
      NULL,
      array_keys($this->buttonTexts)
    );
    arsort($scores);
    $best_id = key($scores);
    $button_text = $this->buttonTexts[$best_id];

    $form = [
      '#type' => 'form',
      '#attributes' => ['class' => ['rl-example-frontend-newsletter-form']],
    ];

    $form['email'] = [
      '#type' => 'email',
      '#placeholder' => $this->t('Enter your email'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $button_text,
      '#ajax' => [
        'callback' => [$this, 'submitCallback'],
      ],
    ];

    // Tell the tracking JS which arm was picked. The rl.php endpoint URL
    // comes from drupalSettings.rl.endpointUrl, published by
    // rl_page_attachments() whenever the rl/api library is loaded.
    $form['#attached']['library'][] = 'rl_example_frontend/frontend_ab_testing';
    $form['#attached']['drupalSettings']['rlExampleFrontend'] = [
      'experimentId' => $this->experimentId,
      'armId' => $best_id,
    ];

    $this->cacheManager->overridePageCacheIfShorter($this->getCacheMaxAge());

    return $form;
  }

  /**
   * AJAX callback for form submission.
   */
  public function submitCallback(array &$form, $form_state) {
    // Reward tracking is handled by Drupal.rl.reward() from the JS click
    // handler, not here.
    $response = new AjaxResponse();
    $response->addCommand(new MessageCommand($this->t('Thanks for subscribing!')));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Short cache lifetime so the server-rendered button text can change
    // as Thompson Sampling scores evolve. 60 seconds trades off learning
    // speed against server load.
    return 60;
  }

}
