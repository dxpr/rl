<?php

namespace Drupal\rl_page_title\Form;

use Drupal\rl\Experiment\VariantExperimentDeleteFormBase;

/**
 * Confirmation form for deleting a Page Title experiment.
 *
 * The base class handles the purge-then-delete sequence; this subclass exists
 * only because the entity annotation needs a concrete form class to reference.
 */
class PageTitleExperimentDeleteForm extends VariantExperimentDeleteFormBase {
}
