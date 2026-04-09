<?php

namespace Drupal\rl_menu_link\Form;

use Drupal\rl\Experiment\VariantExperimentDeleteFormBase;

/**
 * Confirmation form for deleting a Menu Link experiment.
 *
 * The base class handles the purge-then-delete sequence; this subclass exists
 * only because the entity annotation needs a concrete form class to reference.
 */
class MenuLinkExperimentDeleteForm extends VariantExperimentDeleteFormBase {
}
