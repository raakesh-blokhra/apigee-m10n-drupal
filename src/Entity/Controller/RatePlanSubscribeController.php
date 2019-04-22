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

namespace Drupal\apigee_m10n\Entity\Controller;

use Apigee\Edge\Api\Monetization\Entity\Developer;
use Apigee\Edge\Api\Monetization\Entity\PrepaidBalanceInterface;
use Drupal\apigee_m10n\Entity\RatePlanInterface;
use Drupal\apigee_m10n\Entity\Subscription;
use Drupal\apigee_m10n\Form\SubscriptionConfigForm;
use Drupal\apigee_m10n\MonetizationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for subscribing to rate plans.
 */
class RatePlanSubscribeController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Entity form builder.
   *
   * @var \Drupal\Core\Entity\EntityFormBuilderInterface
   */
  protected $entityFormBuilder;

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
   * BillingController constructor.
   *
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entityFormBuilder
   *   Entity form builder service.
   * @param \Drupal\apigee_m10n\MonetizationInterface $monetization
   *   Apigee Monetization utility service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(EntityFormBuilderInterface $entityFormBuilder, MonetizationInterface $monetization, ModuleHandlerInterface $moduleHandler) {
    $this->entityFormBuilder = $entityFormBuilder;
    $this->monetization = $monetization;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.form_builder'),
      $container->get('apigee_m10n.monetization'),
      $container->get('module_handler')
    );
  }

  /**
   * Page callback to create a new subscription.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to subscribe.
   * @param \Drupal\apigee_m10n\Entity\RatePlanInterface $rate_plan
   *   The rate plan.
   *
   * @return array
   *   A subscribe form render array.
   *
   * @throws \Exception
   */
  public function subscribeForm(UserInterface $user, RatePlanInterface $rate_plan) {
    $page = [];

    // Create a subscription to pass to the subscription edit form.
    $subscription = Subscription::create([
      'ratePlan' => $rate_plan,
      'developer' => new Developer(['email' => $user->getEmail()]),
      'startDate' => new \DateTimeImmutable(),
    ]);

    // Get the save label from settings.
    $save_label = $this->config(SubscriptionConfigForm::CONFIG_NAME)->get('subscribe_button_label');
    $save_label = $save_label ?? 'Subscribe';

    // Add the subscribe form with the label set.
    $page['form'] = $this->entityFormBuilder->getForm($subscription, 'default', [
      'save_label' => $this->t($save_label, [
        '@rate_plan' => $rate_plan->getDisplayName(),
        '@username' => $user->label(),
      ]),
    ]);

    // Check if enough balance to subscribe to rate plan.
    if ($this->moduleHandler->moduleExists('apigee_m10n_add_credit')) {
      $prepaid_balances = [];
      foreach ($this->monetization->getDeveloperPrepaidBalances($user, new \DateTimeImmutable('now')) as $prepaid_balance) {
        /* @var PrepaidBalanceInterface $prepaid_balance */
        $prepaid_balances[$prepaid_balance->getCurrency()->id()] = $prepaid_balance->getCurrentBalance();
      }

      // Minimum balance needed is at least the setup fee.
      // @see https://docs.apigee.com/api-platform/monetization/create-rate-plans.html#rateplanops
      $min_balance_needed = $rate_plan->getSetUpFee();
      $currency_id = $rate_plan->getCurrency()->id();

      $addcredit_products = $this->config('apigee_m10n_add_credit.config')->get('products');
      $addcredit_product_id = $addcredit_products[$currency_id]['product_id'] ?? NULL;

      /* @var \Drupal\commerce_product\Entity\ProductInterface $addcredit_product */
      $addcredit_product = $addcredit_product_id ? $this->entityTypeManager()
        ->getStorage('commerce_product')
        ->load($addcredit_product_id) : NULL;

      $prepaid_balances[$currency_id] = $prepaid_balances[$currency_id] ?? 0;
      if ($addcredit_product && $min_balance_needed > $prepaid_balances[$currency_id]) {
        $page['add_credit_message'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('You have insufficient funds to purchase plan %plan.', [
            '%plan' => $rate_plan->label(),
          ]),
        ];
        $page['add_credit_link'] = [
          '#type' => 'link',
          '#title' => $this->t('Add credit'),
          '#url' => $addcredit_product->toUrl(),
        ];

        $page['form']['actions']['submit']['#attributes']['disabled']  = 'disabled';
      }
    }

    return $page;
  }

  /**
   * Gets the title for the subscribe page.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\user\UserInterface|null $user
   *   The user.
   * @param \Drupal\apigee_m10n\Entity\RatePlanInterface|null $rate_plan
   *   The rate plan.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The title.
   */
  public function title(RouteMatchInterface $route_match, UserInterface $user = NULL, RatePlanInterface $rate_plan = NULL) {
    $title_template = $this->config(SubscriptionConfigForm::CONFIG_NAME)->get('subscribe_form_title');
    $title_template = $title_template ?? 'Subscribe to @rate_plan';
    return $this->t($title_template, [
      '@rate_plan' => $rate_plan->getDisplayName(),
      '%rate_plan' => $rate_plan->getDisplayName(),
      '@username' => $user->label(),
      '%username' => $user->label(),
    ]);
  }

}
