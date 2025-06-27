# Custom Email Templates

Place your custom email template files in this directory to override the default email templates.

## Available Templates

- `base.html.twig` - Base email template with header/footer structure
- `mfa-verification.html.twig` - Multi-factor authentication verification email
- `new-account.html.twig` - New account creation email
- `password-reset.html.twig` - Password reset email

## Usage

1. Copy the default template from `../` as a starting point:
   ```bash
   cp ../template-name.html.twig template-name.html.twig
   ```

2. Modify the Twig template to match your requirements

3. Custom templates in this directory will automatically override the defaults

## Notes

- Templates use Twig syntax
- Maintain the same variable structure as the originals
- Support for dark/light mode styling is built-in
- Test email rendering after modifications