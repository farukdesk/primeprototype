# Prime University – Admin Panel

A secure PHP/MySQL admin dashboard with a role-based user group permission system.

## Features

- **Secure Login** with CSRF protection, bcrypt passwords, and session fixation prevention
- **Super Admin Group** with unrestricted access to everything
- **User Groups** – create/edit/delete groups; mark any group as Super Admin
- **Module Access Control** – assign view/create/edit/delete permissions per module per group
- **Users** – full CRUD; users automatically inherit their group's module access
- **Modules** – manage system modules (shown in sidebar only for authorized groups)

## Setup

### 1. Database

```bash
mysql -u root -p < admin/database.sql
```

This creates the `prime_university` database, all required tables, the default `Super Admin` group, core modules, and a default super admin user:

| Field    | Value                           |
|----------|---------------------------------|
| Username | `superadmin`                    |
| Password | `password`                      |
| Email    | `admin@primeuniversity.edu`     |

> **Change the password immediately after first login!**

### 2. Configuration

Edit `admin/includes/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('APP_URL', 'http://yourdomain.com/admin'); // no trailing slash
```

### 3. Web Server

- **Apache**: The included `.htaccess` files restrict direct access to `includes/` files.
- **Nginx**: Add equivalent rules to deny access to `admin/includes/`.

### 4. Access

Navigate to `http://yourdomain.com/primeprototype/admin/` — you will be redirected to the login page.

## Directory Structure

```
admin/
├── index.php              Dashboard
├── login.php              Login page
├── logout.php             Logout handler
├── database.sql           Database schema & seed data
├── includes/
│   ├── config.php         App configuration
│   ├── db.php             PDO database connection
│   ├── auth.php           Session, CSRF, access helpers
│   ├── header.php         Layout header + sidebar
│   └── footer.php         Layout footer
├── users/
│   ├── index.php          User list
│   ├── create.php         Create user
│   ├── edit.php           Edit user
│   └── delete.php         Delete user
├── user-groups/
│   ├── index.php          Group list
│   ├── create.php         Create group
│   ├── edit.php           Edit group
│   └── delete.php         Delete group
├── modules/
│   ├── index.php          Module list
│   ├── create.php         Create module
│   ├── edit.php           Edit module
│   └── delete.php         Delete module
└── access/
    └── index.php          Group → Module access management
```

## Security Notes

- Passwords are hashed with bcrypt (cost 12).
- All forms are CSRF-protected.
- Session ID is regenerated on login.
- Session cookies are `HttpOnly`, `SameSite=Strict`, and `Secure` when using HTTPS.
- The Super Admin group bypass is server-side – never trust client input for access control.
- Core modules (`dashboard`, `users`, `user-groups`, `modules`, `access`) cannot be deleted.
- The Super Admin group cannot be deleted.
- A user cannot delete their own account.
