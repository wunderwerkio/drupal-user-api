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
  case ALREADY_VERIFIED = 'already_verified';
  case PASSWORD_UPDATE_FAILED = 'password_update_failed';
  case CURRENT_PASSWORD_INVALID = 'current_password_invalid';

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
