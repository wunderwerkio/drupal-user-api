# User API module

This module adds multiple REST resources to ease working with user entities via a API.


## Installation

- Enable the module
- Enable REST resources.
- Set permissions for the new REST resources.


## Resources

### Advanced User Registration

This REST resource augments the registration resource from the drupal core `user` module:

- Validates payload and generates a nice JSON response with an array of violations.
- Activates user account for self registration with active email verification.
  This is to fix a mismatch between the drupal core registration form and registration resource.
  When using the registration form of drupal core, the account is enabled with self registration
  and active email verification, but when using the rest resource, it is not.

Otherwise the functionality is the same as the core rest resource.


### Update Password

The update password REST resource handles POST request to change a user's password with proper
handling of the different ways the password is changed:

**Update password with current password**

By passing the current user password and the new password in the request payload,
the password is updated on the user entity.

```json
{
    "newPassword": "new-password",
    "currentPassword": "current-password"
}
```

**Update password with password hash and timestamp**

If the user has forgotten their password, drupal core generates a password hash and a timestamp
when the user uses the "Forgot password" functionality.

By passing the new password, the password hash and the timestamp in the request payload,
the password is updated on the user entity.

```json
{
    "newPassword": "new-password",
    "hash": "password-hash",
    "timestamp": "hash-timestamp"
}
```

*Please note, that it is up to the client implementation to get the password hash and timestamp from
the one time login url that is sent to the user via email.*


## Submodules

Additional functionality is available via the following submodules:

- `user_api_email_confirm`
  Implements handling for changing the user email address with email verification and confirmation.


## Testing & Linting

Tests and Linting can easily be ran via DDEV and the awesome drupal-spoons composer plugin:

*This creates an isolated drupal installation solely for testing.*

- Make sure DDEV is installed.
- Navigate to the module folder.
- Run `ddev start`.

Lint via `ddev phpcs` or `ddev phpcbf`.
Run tests via `ddev phpunit`.

**Change environments**
By running `ddev change-env` the environment (PHP and Drupal version) can be changed.

