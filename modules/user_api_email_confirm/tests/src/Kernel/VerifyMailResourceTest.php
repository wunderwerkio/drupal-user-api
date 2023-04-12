<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_email_confirm\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * VerifyMailResource test.
 *
 * @group user_api
 */
class VerifyMailResourceTest extends EntityKernelTestBase {

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
      'id' => 'user_api_email_confirm_verify_mail',
      'plugin_id' => 'user_api_email_confirm_verify_mail',
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

    $this->url = Url::fromRoute('rest.user_api_email_confirm_verify_mail.POST');
    $this->httpKernel = $this->container->get('http_kernel');

    $this->user = $this->drupalCreateUser([
      'restful post user_api_email_confirm_verify_mail',
    ]);
    $this->setCurrentUser($this->user);

    $anonRole = Role::load(Role::ANONYMOUS_ID);
    $this->grantPermissions($anonRole, ['restful post user_api_email_confirm_verify_mail']);
  }

  /**
   * Test mail change initiation.
   *
   * Test that a notification and verification email is sent
   * when changing the email address.
   */
  public function testMailChangeNotificationAndVerification() {
    $newMail = 'updated-user@example.com';

    $content = [
      'email' => $newMail,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode());

    $mails = $this->getMails();
    $count = count($mails);
    $this->assertEquals(2, $count);
  }

  /**
   * Test mail change initiation with verification disabled.
   */
  public function testDisabledMailChangeVerification() {
    $this->config('user_api_email_confirm.settings')->set('notify.mail_change_verification', FALSE)->save();

    $newMail = 'updated-user@example.com';

    $content = [
      'email' => $newMail,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(500, $response->getStatusCode());

    $mails = $this->getMails();
    $count = count($mails);
    $this->assertEquals(0, $count);
  }

  /**
   * Test mail change with disabled notification.
   */
  public function testMailChangeWithDisabledNotification() {
    $this->config('user_api_email_confirm.settings')->set('notify.mail_change_notification', FALSE)->save();

    $newMail = 'updated-user@example.com';

    $content = [
      'email' => $newMail,
    ];

    $request = $this->createJsonRequest('POST', $this->url->toString(), $content);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode());

    $mails = $this->getMails();
    $count = count($mails);
    $this->assertEquals(1, $count);
  }

  /**
   * Test email change preliminary checks.
   */
  public function testEmailVerifyPreliminaryChecks() {
    // FAILURE - Anonymous account.
    $this->setCurrentUser(User::getAnonymousUser());
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(403, $response->getStatusCode(), $response->getContent());

    // FAILURE - Invalid payload.
    $this->setCurrentUser($this->user);
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);

    $this->assertEquals(422, $response->getStatusCode(), $response->getContent());
  }

}
