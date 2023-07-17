<?php

// @todo Remove once enums are supported in drupal/coder
// phpcs:ignoreFile

namespace Drupal\user_api;

/**
 * Defines the error codes for this module.
 */
enum ErrorCode: string {

  private const PREFIX = 'user_api';

  case UNAUTHENTICATED = 'unauthenticated';
  case INVALID_EMAIL = 'invalid_email';
  case EMAIL_NOT_MATCHING = 'email_not_matching';
  case INVALID_OPERATION = 'invalid_operation';
  case ALREADY_VERIFIED = 'already_verified';
  case PASSWORD_UPDATE_FAILED = 'password_update_failed';
  case CURRENT_PASSWORD_INVALID = 'current_password_invalid';

  case RESEND_MAIL_VISITOR_ACCOUNT_CREATION_DISABLED = 'resend_mail_visitor_account_creation_disabled';
  case RESEND_MAIL_REGISTER_VERIFY_MAIL_DISABLED = 'resend_mail_register_verify_mail_disabled';
  case RESEND_MAIL_ALREADY_CANCELED = 'resend_mail_already_canceled';
  case EMAIL_VERIFICATION_DISABLED = 'email_verification_disabled';

  /**
   * Returns the error code prefixed with the
   * module name.
   *
   * @return string
   *   The error code.
   */
  public function getCode(): string {
    return self::PREFIX . ':' . $this->value;
  }

}
