<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to init account cancellation.
 *
 * @RestResource(
 *   id = "user_api_init_account_cancel",
 *   label = @Translation("Account cancellation initiation"),
 *   uri_paths = {
 *     "create" = "/user-api/init-account-cancel"
 *   }
 * )
 */
class InitAccountCancelResource extends ResourceBase {

  /**
   * The user entity.
   */
  protected UserInterface $user;

  /**
   * Constructs a new InitAccountCancelResource object.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
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
    );
  }

  /**
   * Responds to POST requests.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response indicating success or failure.
   */
  public function post() {
    // User must be logged in.
    $currentUser = $this->getCurrentUser();
    if (!$currentUser->isAuthenticated()) {
      return new JsonResponse(
        ['error' => 'Not logged in!'],
        Response::HTTP_UNAUTHORIZED,
      );
    }

    _user_mail_notify('cancel_confirm', $currentUser);

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
