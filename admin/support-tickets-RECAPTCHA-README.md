# Google reCAPTCHA Integration for Support Tickets

## Overview
This document describes the Google reCAPTCHA integration added to the Prime University IT Support ticket submission system to prevent automated spam attacks.

## Features
- **reCAPTCHA v2 "I'm not a robot" Checkbox**: User-friendly CAPTCHA that requires minimal interaction
- **Admin Control**: Enable/disable CAPTCHA protection via the admin settings panel
- **Secure Key Management**: Site and secret keys stored securely in the database
- **Server-Side Validation**: Comprehensive verification of CAPTCHA responses
- **Public Form Protection**: Applied only to public ticket submissions (not admin users)

## Installation

### 1. Database Migration
Run the SQL migration to add CAPTCHA settings to your database:

```bash
mysql -u your_username -p admin_primepnew2026 < admin/support-tickets-captcha.sql
```

### 2. Get reCAPTCHA Keys
1. Visit [Google reCAPTCHA Admin Console](https://www.google.com/recaptcha/admin)
2. Sign in with your Google account
3. Click **"+"** to create a new site
4. Configure:
   - **Label**: Prime University Support Tickets
   - **reCAPTCHA type**: reCAPTCHA v2 → "I'm not a robot" Checkbox
   - **Domains**: Add `primeuniversity.ac.bd`
5. Accept the reCAPTCHA Terms of Service
6. Click **Submit**
7. Copy your **Site Key** and **Secret Key**

### 3. Configure in Admin Panel
1. Log in to the admin panel
2. Navigate to **IT Support → Settings**
3. Scroll to **Anti-Spam Protection (Google reCAPTCHA)**
4. Check **"Enable CAPTCHA on Public Ticket Submission"**
5. Paste your **Site Key** in the appropriate field
6. Paste your **Secret Key** in the appropriate field
7. Click **Save Settings**

## How It Works

### Front-End (Public Form)
When CAPTCHA is enabled:
1. The reCAPTCHA script loads from Google's CDN
2. A "I'm not a robot" checkbox appears above the submit button
3. Users must complete the CAPTCHA challenge before submitting
4. The form includes a hidden `g-recaptcha-response` token

### Back-End (Validation)
When a ticket is submitted:
1. Server checks if CAPTCHA is enabled in settings
2. If enabled, retrieves the `g-recaptcha-response` token from POST data
3. Sends verification request to Google's reCAPTCHA API with:
   - Secret key
   - Response token
   - User's IP address
4. Google returns success/failure status
5. If verification fails, form submission is rejected with error message
6. If verification succeeds, ticket creation proceeds normally

## Files Modified

### Database Schema
- **File**: `admin/support-tickets-captcha.sql`
- **Changes**: Added 3 settings to `support_settings` table:
  - `captcha_enabled`: '0' or '1'
  - `captcha_site_key`: Public key for widget
  - `captcha_secret_key`: Private key for verification

### Admin Settings Page
- **File**: `admin/support-tickets/settings.php`
- **Changes**:
  - Added CAPTCHA enable/disable toggle
  - Added input fields for site and secret keys
  - Added validation for required keys when enabled
  - Added informational help text with setup instructions
  - Added JavaScript to show/hide key fields based on toggle

### Public Support Portal
- **File**: `support-ticket.php`
- **Changes**:
  - Added `pub_get_setting()` helper function
  - Added `pub_verify_recaptcha()` helper function for Google API verification
  - Added CAPTCHA script tag in HTML head (conditional)
  - Added reCAPTCHA widget above submit button (conditional)
  - Added server-side CAPTCHA validation in form handler

## Security Considerations

### Best Practices Implemented
1. **Secret Key Protection**: Secret key never exposed to client-side code
2. **HTTPS Only**: reCAPTCHA requires HTTPS in production
3. **Server-Side Validation**: Never trust client-side validation alone
4. **IP Address Logging**: Includes user's IP in verification request
5. **CSRF Protection**: Existing CSRF tokens still enforced

### Important Notes
- CAPTCHA is **only applied to public submissions**, not admin users
- Secret keys should be kept confidential
- Google's reCAPTCHA may collect user data (see Google's Privacy Policy)
- Consider adding rate limiting for additional protection

## Testing

### Manual Testing Steps
1. **Enable CAPTCHA**:
   - Go to Admin → IT Support → Settings
   - Enable CAPTCHA and enter valid keys
   - Save settings

2. **Test Public Form**:
   - Visit `/support-ticket.php`
   - Fill out the ticket form
   - Verify CAPTCHA widget appears
   - Try submitting without completing CAPTCHA → Should fail
   - Complete CAPTCHA and submit → Should succeed

3. **Test Invalid Keys**:
   - Enter invalid secret key in settings
   - Try submitting a ticket → Should fail validation

4. **Test Disabled State**:
   - Disable CAPTCHA in settings
   - Visit public form → No CAPTCHA widget should appear
   - Form should submit without CAPTCHA

### Expected Behavior
- ✅ CAPTCHA appears when enabled
- ✅ Submission fails without CAPTCHA completion
- ✅ Submission succeeds with valid CAPTCHA
- ✅ CAPTCHA hidden when disabled
- ✅ Admin users not affected by CAPTCHA

## Troubleshooting

### CAPTCHA Widget Not Showing
- Check if CAPTCHA is enabled in admin settings
- Verify site key is correct
- Check browser console for JavaScript errors
- Ensure domain is registered in reCAPTCHA console

### Validation Always Fails
- Verify secret key is correct
- Check server can connect to `https://www.google.com/recaptcha/api/siteverify`
- Ensure firewall allows outbound HTTPS connections
- Check PHP `allow_url_fopen` is enabled for `file_get_contents()`

### "CAPTCHA verification failed" Error
- User didn't complete the CAPTCHA
- Network timeout during verification
- Invalid/expired response token
- Keys don't match the domain

## API Reference

### Helper Functions

#### `pub_get_setting(string $key, string $default = ''): string`
Retrieves a setting value from the database.

**Parameters**:
- `$key`: Setting key name
- `$default`: Default value if setting not found

**Returns**: Setting value or default

---

#### `pub_verify_recaptcha(string $response, string $secret_key): bool`
Verifies a reCAPTCHA response with Google's API.

**Parameters**:
- `$response`: The `g-recaptcha-response` token from form
- `$secret_key`: Your reCAPTCHA secret key

**Returns**: `true` if verification succeeds, `false` otherwise

**Example**:
```php
$captcha_response = $_POST['g-recaptcha-response'] ?? '';
$secret_key = pub_get_setting('captcha_secret_key', '');

if (!pub_verify_recaptcha($captcha_response, $secret_key)) {
    $errors[] = 'CAPTCHA verification failed.';
}
```

## Future Enhancements
- Add support for reCAPTCHA v3 (invisible CAPTCHA)
- Implement rate limiting per IP address
- Add honeypot fields for additional bot detection
- Log failed CAPTCHA attempts for security monitoring
- Support for alternative CAPTCHA providers (hCaptcha, Cloudflare Turnstile)

## Support
For issues or questions:
- Email: dd.it@primeuniversity.ac.bd
- Admin Panel: IT Support → Settings

## License
This feature is part of the Prime University IT Support system.

---

**Last Updated**: 2026-05-06  
**Version**: 1.0  
**Author**: Copilot SWE Agent
