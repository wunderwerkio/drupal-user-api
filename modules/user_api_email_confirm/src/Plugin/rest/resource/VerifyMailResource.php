<?php

declare(strict_types=1);

namespace Drupal\user_api_email_confirm\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a resource to change user email with confirmation.
 *
 * @RestResource(
 *   id = "user_api_email_confirm_verify_mail",
 *   label = @Translation("Send verification email for email change."),
 *   uri_paths = {
 *     "create" = "/user-api/verify-email"
 *   }
 * )
 */
class VerifyMailResource extends ResourceBase {

  /**
   * The user entity.
   */
  protected UserInterface $user;

  /**
   * Constructs a new OneTimeLoginResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactory $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Responds to POST requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response indicating success or failure.
   */
  public function post(Request $request) {
    $user = $this->getCurrentUser();
    if (!$user) {
      return new JsonResponse(['error' => 'User not logged in.'], Response::HTTP_UNAUTHORIZED);
    }
    $this->user = $user;

    // Resource does not support handling of email change without verification.
    if (!$this->isVerificationEnabled()) {
      return new JsonResponse([
        'error' => 'Verification is disabled. Please change email via JSON:API.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    // Validate payload.
    $payload = $request->getContent();
    $data = Json::decode($payload);

    if (!array_key_exists('email', $data)) {
      return new JsonResponse([
        'error' => 'Invalid payload: Missing field "mail".',
      ], Response::HTTP_BAD_REQUEST);
    }

    $mail = $data['email'];

    return $this->handleVerifyMailChange($mail);
  }

  /**
   * Handle password update with current password.
   *
   * @param string $mail
   *   The new email address.
   */
  protected function handleVerifyMailChange(string $mail) {
    $clonedAccount = clone $this->user;
    $clonedAccount->setEmail($mail);

    _user_api_email_confirm_mail_notify('mail_change_verification', $clonedAccount);

    if ($this->isNotificationEnabled()) {
      _user_api_email_confirm_mail_notify('mail_change_notification', $this->user);
    }

    return new JsonResponse([
      'status' => 'success',
      'message' => 'A verification email has been sent to the new email address.',
    ]);
  }

  /**
   * Loads the user entity for the current user.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity.
   */
  protected function getCurrentUser(): ?UserInterface {
    return $this->entityTypeManager->getStorage('user')->load(
      $this->currentUser->id()
    );
  }

  /**
   * Checks if verification email is enabled.
   */
  protected function isVerificationEnabled(): bool {
    return $this->getConfig()->get('notify.mail_change_verification');
  }

  /**
   * Checks if notification email is enabled.
   */
  protected function isNotificationEnabled(): bool {
    return $this->getConfig()->get('notify.mail_change_notification');
  }

  /**
   * Get module config.
   */
  protected function getConfig() {
    return $this->configFactory->get('user_api_email_confirm.settings');
  }

}
