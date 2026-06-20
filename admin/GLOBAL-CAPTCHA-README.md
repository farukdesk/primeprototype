# Global reCAPTCHA v2 Implementation

This implementation adds Google reCAPTCHA v2 protection to all public submission forms to prevent automated spam.

## Protected Forms

The following public forms are protected with CAPTCHA:

1. **Apply Now** (`apply-now.php`) - Lead application form
2. **Contact** (`contact.php`) - Contact form
3. **Certificate Verification** (`certificate-verification.php`) - Certificate verification lookup
4. **Student Enrollment Status** (`student-enrollment-status.php`) - Enrollment status lookup
5. **Faculty Registration** (`faculty-register.php`) - Faculty registration form
6. **Job Applications** (`job-detail.php`) - Job application submission

## Installation

### 1. Run Database Migration

Execute the SQL migration to create the `global_settings` table and add CAPTCHA settings:

```bash
mysql -u username -p database_name < admin/global-captcha-settings.sql
```

### 2. Get Google reCAPTCHA Keys

1. Visit [Google reCAPTCHA Admin Console](https://www.google.com/recaptcha/admin)
2. Click "+" to create a new site
3. Select **reCAPTCHA v2** → **"I'm not a robot" Checkbox**
4. Add your domain: `primeuniversity.ac.bd`
5. Copy the **Site Key** and **Secret Key**

### 3. Configure CAPTCHA Settings

1. Log in to the admin panel
2. Navigate to **Settings → CAPTCHA Settings** (`/admin/settings/captcha.php`)
3. Enable CAPTCHA protection
4. Paste your Site Key and Secret Key
5. Click "Save Settings"

## Implementation Details

### Files Modified

- `apply-now.php` - Added CAPTCHA verification
- `contact.php` - Added CAPTCHA verification
- `certificate-verification.php` - Added CAPTCHA verification
- `student-enrollment-status.php` - Added CAPTCHA verification
- `faculty-register.php` - Added CAPTCHA verification
- `job-detail.php` - Added CAPTCHA verification

### New Files Created

- `admin/global-captcha-settings.sql` - Database migration
- `includes/captcha-helpers.php` - Reusable CAPTCHA functions
- `admin/settings/captcha.php` - Admin settings page

### Helper Functions (`includes/captcha-helpers.php`)

All CAPTCHA functionality is centralized in helper functions:

```php
captcha_is_enabled()      // Check if CAPTCHA is enabled
captcha_site_key()        // Get the site key
captcha_verify_request()  // Verify CAPTCHA from POST request
captcha_render_widget()   // Output the CAPTCHA widget HTML
captcha_render_script()   // Output the reCAPTCHA script tag
```

### Usage Example

```php
// 1. Include the helper file
require_once __DIR__ . '/includes/captcha-helpers.php';

// 2. Add server-side verification in form processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CAPTCHA
    if (!captcha_verify_request()) {
        $form_errors[] = 'CAPTCHA verification failed.';
    }
    // ... rest of form processing
}

// 3. Add script tag in <head>
<?php captcha_render_script(); ?>

// 4. Add widget before submit button
<?php captcha_render_widget(); ?>
```

## How It Works

1. **Frontend**: The `captcha_render_script()` function loads Google's reCAPTCHA JavaScript
2. **Form Display**: The `captcha_render_widget()` function displays the "I'm not a robot" checkbox
3. **Form Submission**: When the user submits the form, the browser sends a `g-recaptcha-response` token
4. **Backend Verification**: The `captcha_verify_request()` function validates the token with Google's API
5. **Result**: If verification fails, the form is not processed and an error is shown to the user

## Security Features

- Server-side verification prevents bypassing the CAPTCHA
- Secret key is never exposed to the client
- CAPTCHA can be disabled globally from admin settings
- Uses secure HTTPS communication with Google's API
- 5-second timeout prevents hanging requests
- Error logging for debugging verification failures

## Privacy Considerations

Google reCAPTCHA collects user data for bot detection. Users should be informed via your privacy policy. Consider adding a privacy notice near the CAPTCHA widget:

```html
<div class="form-text">
    This site is protected by reCAPTCHA and the Google
    <a href="https://policies.google.com/privacy">Privacy Policy</a> and
    <a href="https://policies.google.com/terms">Terms of Service</a> apply.
</div>
```

## Troubleshooting

### CAPTCHA not showing
- Verify CAPTCHA is enabled in admin settings
- Check that site key is correctly configured
- Ensure the domain matches the one registered with Google

### "Invalid site key" error
- Confirm the site key matches your Google reCAPTCHA configuration
- Verify the domain in Google reCAPTCHA admin matches your website domain

### Verification always fails
- Check that the secret key is correctly configured
- Ensure your server can access `https://www.google.com/recaptcha/api/siteverify`
- Check error logs for details

### CAPTCHA shows but verification fails
- Verify the secret key is correct and not expired
- Check that the time zone is correctly set on your server
- Review error logs for API response details

## Testing

To test CAPTCHA functionality:

1. **Enable CAPTCHA**: Turn on CAPTCHA in admin settings
2. **Test with valid interaction**: Complete the CAPTCHA and submit a form - should succeed
3. **Test without completing CAPTCHA**: Try submitting without clicking the checkbox - should fail with error message
4. **Disable CAPTCHA**: Turn off CAPTCHA in settings and verify forms work without it

## Related Implementation

This implementation is similar to the reCAPTCHA integration in the IT Support ticket system (pull/363), but uses a global settings table instead of module-specific settings.

## Support

For issues or questions:
- Review the error logs at `/path/to/error.log`
- Check the [Google reCAPTCHA documentation](https://developers.google.com/recaptcha/docs/display)
- Contact the development team
