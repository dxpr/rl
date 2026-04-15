<?php

namespace Drupal\rl_example\Plugin\Block;

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
 * @Block(
 *   id = "rl_example_newsletter",
 *   admin_label = @Translation("RL Example Newsletter Signup"),
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
    $this->experimentId = 'rl_example-newsletter_button';

    // Register our experiment.
    $this->experimentRegistry->register(
      $this->experimentId,
      'rl_example',
      'Newsletter Button A/B Test'
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
    // Get RL's recommendation for button text.
    $scores = $this->experimentManager->getThompsonScores(
      $this->experimentId,
      NULL,
      array_keys($this->buttonTexts)
    );

    // Pick the best one.
    arsort($scores);
    $best_id = key($scores);
    $button_text = $this->buttonTexts[$best_id];

    // Build minimal form.
    $form = [
      '#type' => 'form',
      '#attributes' => ['class' => ['rl-example-newsletter-form']],
    ];

    $form['arm_id'] = [
      '#type' => 'hidden',
      '#value' => $best_id,
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

    // Attach JavaScript library for viewport tracking. The rl.php endpoint
    // URL comes from drupalSettings.rl.endpointUrl, published by
    // rl_page_attachments() whenever the rl/api library is loaded.
    $form['#attached']['library'][] = 'rl_example/tracking';
    $form['#attached']['drupalSettings']['rlExample']['tracking'] = [
      'experimentId' => $this->experimentId,
      'armId' => $best_id,
    ];

    // Override page cache if block cache is shorter than site cache.
    $this->cacheManager->overridePageCacheIfShorter($this->getCacheMaxAge());

    return $form;
  }

  /**
   * AJAX callback for form submission.
   */
  public function submitCallback(array &$form, $form_state) {
    // Record the reward - user submitted the form.
    $arm_id = $form_state->getValue('arm_id');
    $this->experimentManager->recordReward($this->experimentId, $arm_id);

    // Create AJAX response with success message.
    $response = new AjaxResponse();
    $response->addCommand(new MessageCommand($this->t('Thanks for subscribing!')));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Set 60-second cache to allow button text to change based on RL scores.
    // With a longer cache lifetime, we sacrifice testing efficiency for server
    // load - the algorithm learns slower but servers work less hard.
    return 60;
  }

}
