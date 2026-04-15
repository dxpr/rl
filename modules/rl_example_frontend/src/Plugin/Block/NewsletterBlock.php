<?php

namespace Drupal\rl_example_frontend\Plugin\Block;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a newsletter signup block with frontend A/B tested button text.
 *
 * @Block(
 *   id = "rl_example_frontend_newsletter",
 *   admin_label = @Translation("RL Example Frontend Newsletter Signup"),
 * )
 */
class NewsletterBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    ExperimentRegistryInterface $experiment_registry,
    MessengerInterface $messenger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->experimentRegistry = $experiment_registry;
    $this->messenger = $messenger;

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
      $container->get('rl.experiment_registry'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Build minimal form with fixed button text - JavaScript will override it.
    $form = [
      '#type' => 'form',
      '#attributes' => ['class' => ['rl-example-frontend-newsletter-form']],
    ];

    $form['email'] = [
      '#type' => 'email',
      '#placeholder' => $this->t('Enter your email'),
      '#required' => TRUE,
    ];

    // Fixed button text that JavaScript will replace.
    $form['submit'] = [
      '#type' => 'submit',
    // Default fallback text.
      '#value' => 'Subscribe',
      '#ajax' => [
        'callback' => [$this, 'submitCallback'],
      ],
    ];

    // Attach JavaScript library and settings for frontend A/B testing. The
    // rl.php endpoint URL comes from drupalSettings.rl.endpointUrl, which
    // rl_page_attachments() publishes when the rl/api library is loaded.
    $form['#attached']['library'][] = 'rl_example_frontend/frontend_ab_testing';
    $form['#attached']['drupalSettings']['rlExampleFrontend'] = [
      'experimentId' => $this->experimentId,
      'buttonTexts' => $this->buttonTexts,
    ];

    return $form;
  }

  /**
   * AJAX callback for form submission.
   */
  public function submitCallback(array &$form, $form_state) {
    // Note: Reward tracking is handled by JavaScript in this frontend example.
    // The JavaScript sends the reward signal directly to rl.php.
    // Create AJAX response with success message.
    $response = new AjaxResponse();
    $response->addCommand(new MessageCommand($this->t('Thanks for subscribing!')));

    return $response;
  }

}
