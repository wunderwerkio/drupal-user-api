<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdvancedUserRegistration test.
 *
 * @group user_api
 */
class AdvancedUserRegistrationTest extends EntityKernelTestBase {

  use UserApiTestTrait;
  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'rest',
    'serialization',
    'user_api',
  ];

  /**
   * The URL to the resource.
   *
   * @var \Drupal\Core\Url
   */
  protected $url;

  /**
   * The kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * User settings config instance.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $userSettings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['user']);
    $this->installSchema('user', ['users_data']);

    RestResourceConfig::create([
      'id' => 'user_api_user_registration',
      'plugin_id' => 'user_api_user_registration',
      'granularity' => RestResourceConfig::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['POST'],
        'formats' => ['json'],
        'authentication' => ['cookie'],
      ],
    ])->save();

    $this->drupalSetUpCurrentUser();
    $this->setCurrentUser(User::getAnonymousUser());
    $this->grantPermissions(Role::load(Role::ANONYMOUS_ID), ['restful post user_api_user_registration']);

    $this->userSettings = $this->config('user.settings');

    $this->userSettings
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->set('verify_mail', TRUE)
      ->save();

    $this->url = Url::fromRoute('rest.user_api_user_registration.POST');
    $this->httpKernel = $this->container->get('http_kernel');
  }

  /**
   * Test register user with verify email.
   */
  public function testRegisterUserWithVerifyEmail() {
    // Test with username and email.
    $username = 'test_user_' . time();

    $content = [
      'name' => [
        'value' => $username,
      ],
      'mail' => [
        'value' => $username . '@example.com',
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertValidRegistrationResponse($response);
    $this->assertUser($username, TRUE, TRUE);

    // Email is required when verify email is enabled.
    $content = [
      'name' => [
        'value' => $username,
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(422, $response->getStatusCode());

    // Error constraints must be in the response.
    $parsedResponse = Json::decode($response->getContent());
    $this->assertArrayHasKey('errors', $parsedResponse);
    $this->assertArrayHasKey('mail', $parsedResponse['errors']);
    $this->assertArrayHasKey('constraint', $parsedResponse['errors']['mail']);

    // With verify email, setting a password is not allowed.
    $content = [
      'name' => [
        'value' => $username,
      ],
      'mail' => [
        'value' => $username . '@example.com',
      ],
      'pass' => [
        'value' => 'test_password',
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(422, $response->getStatusCode());
  }

  /**
   * Test register user without verify email.
   */
  public function testRegisterUserWithoutVerifyEmail() {
    $this->userSettings
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->set('verify_mail', FALSE)
      ->save();

    // Test with username, email and password.
    $username = 'test_user_' . time();

    $content = [
      'name' => [
        'value' => $username,
      ],
      'mail' => [
        'value' => $username . '@example.com',
      ],
      'pass' => [
        'value' => 'test_password',
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertValidRegistrationResponse($response);
    $this->assertUser($username, TRUE, TRUE);

    // Email is still required when verify email is disabled.
    $username = 'test_user_' . time();

    $content = [
      'name' => [
        'value' => $username,
      ],
      'pass' => [
        'value' => 'test_password',
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(422, $response->getStatusCode());
  }

  /**
   * Test register user with verify email and admin approval.
   */
  public function testRegisterUserWithVerifyEmailAndAdminApproval() {
    $this->userSettings
      ->set('register', UserInterface::REGISTER_VISITORS_ADMINISTRATIVE_APPROVAL)
      ->set('verify_mail', TRUE)
      ->save();

    // Test with username and email.
    $username = 'test_user_' . time();

    $content = [
      'name' => [
        'value' => $username,
      ],
      'mail' => [
        'value' => $username . '@example.com',
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertValidRegistrationResponse($response);
    // User must be blocked.
    $this->assertUser($username, TRUE, FALSE);
  }

  /**
   * Test register user without verify email and admin approval.
   */
  public function testRegisterUserWithoutVerifyEmailAndAdminApproval() {
    $this->userSettings
      ->set('register', UserInterface::REGISTER_VISITORS_ADMINISTRATIVE_APPROVAL)
      ->set('verify_mail', FALSE)
      ->save();

    // Test with username, email and password.
    $username = 'test_user_' . time();

    $content = [
      'name' => [
        'value' => $username,
      ],
      'mail' => [
        'value' => $username . '@example.com',
      ],
      'pass' => [
        'value' => 'test_password',
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertValidRegistrationResponse($response);
    $this->assertUser($username, TRUE, FALSE);
  }

  /**
   * Test user registration email notification.
   */
  public function testRegisterUserEmailNotification() {
    // Test with username and email.
    $username = 'test_user_' . time();

    $content = [
      'name' => [
        'value' => $username,
      ],
      'mail' => [
        'value' => $username . '@example.com',
      ],
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $this->httpKernel->handle($request);

    $count = count($this->getMails());
    $this->assertEquals(1, $count, 'One email was sent.');
  }

  /**
   * Asserts that a user with the given username exists.
   *
   * @param string $username
   *   The username.
   * @param bool $exists
   *   If user should exist.
   * @param bool $status
   *   If user should be active.
   */
  protected function assertUser(string $username, bool $exists, bool $status) {
    $result = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $username]);

    if (empty($result)) {
      $this->assertFalse($exists, sprintf('User with username %s does not exist!', $username));
      return;
    }

    $this->assertTrue($exists, sprintf('User with username %s exists!', $username));
    $this->assertEquals($status, reset($result)->isActive(), sprintf('User with username %s has status %s!', $username, $status));
  }

  /**
   * Asserts that the response is a valid registration response.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   */
  protected function assertValidRegistrationResponse(Response $response) {
    $this->assertEquals(200, $response->getStatusCode());
    $parsedResponse = Json::decode($response->getContent());

    $this->assertArrayHasKey('uid', $parsedResponse);
    $this->assertArrayHasKey('name', $parsedResponse);
  }

}
