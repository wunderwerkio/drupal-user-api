<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_email_confirm\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\verification_hash\VerificationHashManager;

/**
 * AdvancedUserRegistrationResource test.
 *
 * @group user_api
 */
class UpdateMailResourceTest extends EntityKernelTestBase {

  use UserApiTestTrait;
  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'rest',
    'serialization',
    'user_api',
    'user_api_email_confirm',
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
    $this->installConfig(['user_api_email_confirm']);

    $this->setUpCurrentUser();

    RestResourceConfig::create([
      'id' => 'user_api_email_confirm_update_mail',
      'plugin_id' => 'user_api_email_confirm_update_mail',
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

    $this->url = Url::fromRoute('rest.user_api_email_confirm_update_mail.POST');
    $this->httpKernel = $this->container->get('http_kernel');

    $this->user = $this->drupalCreateUser([
      'restful post user_api_email_confirm_update_mail',
    ]);
    $this->setCurrentUser($this->user);

    $this->hashManager = $this->container->get('verification_hash.manager');
  }

  /**
   * Test direct email change on entity.
   */
  public function testDirectlyChangingEmail() {
    $newMail = 'updated-user@example.com';

    // The user cannot change the email directly.
    $exception = NULL;

    try {
      $this->user->setEmail($newMail)->save();
    }
    catch (\Throwable $e) {
      $exception = $e;
    }

    $this->assertNotNull($exception);
    $this->assertEquals($exception->getMessage(), 'Cannot change email directly!');

    // Another user (e.g. admin) can directly change email.
    $this->setCurrentUser(User::load(1));

    $exception = NULL;

    try {
      $this->user->setEmail($newMail)->save();
    }
    catch (\Throwable $e) {
      $exception = $e;
    }

    $this->assertNull($exception);
    $this->assertEquals($newMail, $this->user->getEmail());
  }

  /**
   * Test direct email change with verification disabled.
   */
  public function testDirectlyChangingEmailWithVerificationDisabled() {
    $this->config('user_api_email_confirm.settings')->set('notify.mail_change_verification', FALSE)->save();

    $newMail = 'updated-user@example.com';

    // The user cannot change the email directly.
    $exception = NULL;

    try {
      $this->user->setEmail($newMail)->save();
    }
    catch (\Throwable $e) {
      $exception = $e;
    }

    $this->assertNull($exception);
    $this->assertEquals($newMail, $this->user->getEmail());
  }

  /**
   * Test email change with hash.
   *
   * This assumes the user has got the hash and timestamp via email.
   */
  public function testEmailChangeWithHash() {
    $newMail = 'updated-user@example.com';

    $timestamp = \Drupal::time()->getRequestTime();
    $hash = $this->hashManager->createHash($this->user, 'set-email', $timestamp, '', $newMail);

    $content = [
      'email' => $newMail,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $request->headers->set('X-Verification-Hash', sprintf('%s$$%s', $hash, $timestamp));
    $response = $this->httpKernel->handle($request);

    $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

    // Reload updated user from storage.
    $updated = $this->user->load($this->user->id());
    $this->assertEquals($newMail, $updated->getEmail());
  }

  /**
   * Test mail change with expired hash.
   */
  public function testMailChangeWithExpiredHash() {
    $this->config('user_api_email_confirm.settings')->set('mail_change_timeout', 60)->save();
    $newMail = 'updated-user@example.com';

    // Expiry is 60 seconds.
    // By subtracting 61 secons from now, it is already expired.
    $timestamp = time() - 61;
    $hash = $this->hashManager->createHash($this->user, 'set-email', $timestamp, '', $newMail);

    $content = [
      'email' => $newMail,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $request->headers->set('X-Verification-Hash', sprintf('%s$$%s', $hash, $timestamp));
    $response = $this->httpKernel->handle($request);

    $this->assertEquals(403, $response->getStatusCode(), $response->getContent());
    $this->assertStringContainsString("hash_invalid", $response->getContent());
  }

  /**
   * Test mail change with invalid payload.
   */
  public function testMailChangeWithInvalidPayload() {
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);

    $this->assertEquals(422, $response->getStatusCode(), $response->getContent());
  }

}
