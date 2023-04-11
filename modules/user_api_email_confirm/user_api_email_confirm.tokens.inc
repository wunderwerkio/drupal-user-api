<?php

/**
 * @file
 * Token definitions for user_api_email_confirm.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Implements hook_token_info().
 */
function user_api_email_confirm_token_info() {
  $info = [
    'tokens' => [
      'user' => [
        'magic-link-email-verification' => [
          'name' => new TranslatableMarkup('Magic Link for E-Mail verification'),
          'description' => new TranslatableMarkup('A magic link with additional params for email verification.'),
          'restricted' => TRUE,
        ],
      ],
    ],
  ];

  return $info;
}

/**
 * Implements hook_tokens().
 */
function user_api_email_confirm_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];

  if ($type == 'user' && !empty($data['user'])) {
    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'magic-link-email-verification':
          $replacements[$original] = '[user:magic-link-email-verification_mail-only]';
          break;
      }
    }
  }

  return $replacements;
}
