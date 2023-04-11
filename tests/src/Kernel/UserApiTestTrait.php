<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * User API test trait.
 */
trait UserApiTestTrait {

  /**
   * Creates a JSON request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $uri
   *   The URI.
   * @param array $content
   *   The content.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function createJsonRequest(string $method, string $uri, array $content): Request {
    $encodedContent = Json::encode($content);

    $request = Request::create($uri, $method, [], [], [], [], $encodedContent);
    $request->headers->set('Content-Type', 'application/json');

    return $request;
  }

  /**
   * Asserts that the given $rawPassword is the $user password.
   *
   * @param string $rawPassword
   *   The raw password.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to check.
   */
  protected function assertUserPasswordEquals(string $rawPassword, AccountInterface $user) {
    // Reload user.
    $user = User::load($user->id());

    $passwordChecker = \Drupal::service('password');
    $this->assertTrue($passwordChecker->check($rawPassword, $user->getPassword()), 'User password is not set to ' . $rawPassword . '.');
  }

}
