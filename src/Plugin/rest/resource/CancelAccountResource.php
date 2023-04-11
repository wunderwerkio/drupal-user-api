<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\user\UserInterface;
use Drupal\user_api\ErrorCode;
use Drupal\verification\Service\RequestVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Wunderwerk\JsonApiError\JsonApiErrorResponse;

/**
 * Provides a resource to cancel a user's account.
 *
 * @RestResource(
 *   id = "user_api_cancel_account",
 *   label = @Translation("Cancel Account"),
 *   uri_paths = {
 *     "create" = "/user-api/cancel-account"
 *   }
 * )
 */
class CancelAccountResource extends ResourceBase {

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\verification\Service\RequestVerifier $verifier
   *   The request verifier service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected RequestVerifier $verifier,
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
      $container->get('verification.request_verifier'),
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
    if (!$user || !$user->isAuthenticated()) {
      return JsonApiErrorResponse::fromError(
        status: 403,
        code: ErrorCode::UNAUTHENTICATED->getCode(),
        title: 'Unauthenticated',
        detail: 'You are not authenticated.',
      );
    }

    $result = $this->verifier->verifyOperation($request, 'cancel-account', $user);
    if ($response = $result->toErrorResponse()) {
      return $response;
    }

    return $this->cancelAccount($user);
  }

  /**
   * Cancel the given user account.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user account to cancel.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function cancelAccount(UserInterface $user): Response {
    $cancelMethod = $this->configFactory->get('user.settings')->get('cancel_method');

    user_cancel([], $user->id(), $cancelMethod);

    // Since user_cancel() is not invoked via Form API, batch processing
    // needs to be invoked manually.
    $batch =& batch_get();
    // Mark this batch as non-progressive to bypass the progress bar and
    // redirect.
    $batch['progressive'] = FALSE;
    batch_process();

    return new JsonResponse([
      'status' => 'success',
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

}
