<?php

/*
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\apigee_m10n\Entity\Form;

use Apigee\Edge\Exception\ClientErrorException;
use Drupal\apigee_m10n\MonetizationInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\apigee_edge\Entity\Developer;

/**
 * Subscription entity form.
 */
class SubscriptionForm extends FieldableMonetizationEntityForm {

  /**
   * Developer legal name attribute name.
   */
  const LEGAL_NAME_ATTR = 'MINT_DEVELOPER_LEGAL_NAME';

  /*
   * Insufficient funds API error code.
   */
  const INSUFFICIENT_FUNDS_ERROR = 'mint.insufficientFunds';

  /**
   * Messanger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Apigee Monetization utility service.
   *
   * @var \Drupal\apigee_m10n\MonetizationInterface
   */
  protected $monetization;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a SubscriptionEditForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   * @param \Drupal\apigee_m10n\MonetizationInterface $monetization
   *   Apigee Monetization utility service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(MessengerInterface $messenger = NULL, MonetizationInterface $monetization, ModuleHandlerInterface $moduleHandler) {
    $this->messenger = $messenger;
    $this->monetization = $monetization;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('apigee_m10n.monetization'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    // @TODO: Make sure we find a better way to handle names
    // without adding rate plan ID this form is getting cached
    // and when rendered as a formatter.
    // Also known issue in core @see https://www.drupal.org/project/drupal/issues/766146.
    return parent::getFormId() . '_' . $this->entity->getRatePlan()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    // Redirect to Rate Plan detail page on submit.
    $form['#action'] = $this->getEntity()->getRatePlan()->url('subscribe');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    // Set the save label if one has been passed into storage.
    if (!empty($actions['submit']) && ($save_label = $form_state->get('save_label'))) {
      $actions['submit']['#value'] = $save_label;
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    try {
      // Auto assign legal name.
      $developer_id = $this->entity->getDeveloper()->getEmail();
      $developer = Developer::load($developer_id);
      // Autopopulate legal name when developer has no legal name attribute set.
      if (empty($developer->getAttributeValue(static::LEGAL_NAME_ATTR))) {
        $developer->setAttribute(static::LEGAL_NAME_ATTR, $developer_id);
        $developer->save();
      }

      $display_name = $this->entity->getRatePlan()->getDisplayName();
      Cache::invalidateTags(['apigee_my_subscriptions']);

      if ($this->entity->save()) {
        $this->messenger->addStatus($this->t('You have purchased %label plan', [
          '%label' => $display_name,
        ]));
        $form_state->setRedirect('entity.subscription.developer_collection', ['user' => $this->entity->getOwnerId()]);
      }
      else {
        $this->messenger->addWarning($this->t('Unable to purchase %label plan', [
          '%label' => $display_name,
        ]));
      }
    }
    catch (\Exception $e) {
      $previous = $e->getPrevious();

      // If insufficient funds error, format nicely and add link to add credit.
      if ($previous instanceof ClientErrorException && $previous->getEdgeErrorCode() === static::INSUFFICIENT_FUNDS_ERROR) {
        preg_match_all('/\[(?\'amount\'.+)\]/', $e->getMessage(), $matches);
        $amount = $matches['amount'][0] ?? NULL;
        $rate_plan = $this->getEntity()->getRatePlan();
        $currency_id = $rate_plan->getCurrency()->id();

        $message = 'You have insufficient funds to purchase plan %plan.';
        $message .= $amount ? ' To purchase this plan you are required to add at least %amount to your account.' : '';
        $params = [
          '%plan' => $rate_plan->label(),
          '%amount' => $this->monetization->formatCurrency($matches['amount'][0], $currency_id),
        ];

        if ($this->moduleHandler->moduleExists('apigee_m10n_add_credit')) {
          $addcredit_products = $this->config('apigee_m10n_add_credit.config')->get('products');
          if (!empty($addcredit_products[$currency_id]['product_id'])) {
            /* @var \Drupal\commerce_product\Entity\ProductInterface $addcredit_product */
            $addcredit_product = $this->entityTypeManager
              ->getStorage('commerce_product')
              ->load($addcredit_products[$currency_id]['product_id']);
          }
          if (!empty($addcredit_product)) {
            $message .= ' @link';
            $params['@link'] = \Drupal::service('link_generator')->generate($this->t('Add credit'), $addcredit_product->toUrl());
          }
        }

        $this->messenger->addError($this->t($message, $params));
      }
      else {
        $this->messenger->addError($e->getMessage());
      }
    }
  }

}
