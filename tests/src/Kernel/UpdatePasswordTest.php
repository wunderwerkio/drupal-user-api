<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_email_confirm\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\UserInterface;
use Drupal\verification_hash\VerificationHashManager;

/**
 * UpdatePassword test.
 *
 * @group user_api
 */
class UpdatePasswordTest extends EntityKernelTestBase {

  use UserApiTestTrait;
  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'rest',
    'serialization',
    'user_api',
    'verification',
    'verification_hash',
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
   * The user.
   */
  protected UserInterface $user;

  /**
   * The user password.
   */
  protected string $password = 'password';

  /**
   * The hash manager.
   */
  protected VerificationHashManager $hashManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['user']);

    $this->setUpCurrentUser();

    RestResourceConfig::create([
      'id' => 'user_api_update_password',
      'plugin_id' => 'user_api_update_password',
      'granularity' => RestResourceConfig::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['POST'],
        'formats' => ['json'],
        'authentication' => ['cookie'],
      ],
    ])->save();

    $this->userSettings = $this->config('user.settings');

    $this->userSettings
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->set('verify_mail', TRUE)
      ->save();

    $this->url = Url::fromRoute('rest.user_api_update_password.POST');
    $this->httpKernel = $this->container->get('http_kernel');

    $this->user = $this->drupalCreateUser([
      'restful post user_api_update_password',
    ]);
    $this->user->setPassword($this->password)->save();
    $this->setCurrentUser($this->user);

    $this->hashManager = $this->container->get('verification_hash.manager');
  }

  /**
   * Test change password with old password.
   */
  public function testPasswordChangeWithOldPassword() {
    $newPass = 'new-password';

    $content = [
      'newPassword' => $newPass,
      'currentPassword' => $this->password,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode());

    $this->assertUserPasswordEquals($newPass, $this->user);
  }

  /**
   * Test change password with hash.
   */
  public function testPasswordChangeWithHash() {
    $newPass = 'new-password';

    $timestamp = time();
    $hash = $this->hashManager->createHash($this->user, 'set-password', $timestamp);

    $content = [
      'newPassword' => $newPass,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $request->headers->set('X-Verification-Hash', sprintf('%s$$%s', $hash, $timestamp));
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode());

    $this->assertUserPasswordEquals($newPass, $this->user);

    // Invalid hash.
    $content = [
      'newPassword' => $newPass,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $request->headers->set('X-Verification-Hash', sprintf('%s$$%s', 'invalid-hash', $timestamp));
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Test invalid payload.
   */
  public function testInvalidPayload() {
    $content = [
      'unknown' => 'newPass',
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(422, $response->getStatusCode());
  }

}
