# VIKOBA Management System
**Version:** 1.0.0 | **Stack:** PHP 8+ OOP · MySQL · Bootstrap 5

---

## 📁 Project Structure

```
vikoba/
├── index.php                  # Login page
├── .htaccess                  # Apache security & routing
├── config/
│   └── config.php             # App config (DB credentials, constants)
├── classes/
│   ├── Database.php           # PDO singleton
│   ├── Auth.php               # Login / logout / session
│   ├── Member.php             # Member CRUD
│   ├── Contribution.php       # Contributions & cycles
│   ├── Loan.php               # Loans & repayments
│   └── Fine.php               # Fines & penalties
├── includes/
│   ├── bootstrap.php          # Loads all classes & helpers
│   ├── header.php             # Sidebar + topbar layout
│   └── footer.php             # Scripts + closing tags
├── pages/
│   ├── dashboard.php          # Main dashboard
│   ├── members.php            # Member management
│   ├── contributions.php      # Contributions
│   ├── loans.php              # Loans & repayments
│   ├── fines.php              # Fines & penalties
│   ├── reports.php            # Reports & analytics
│   ├── users.php              # User management (admin)
│   └── logout.php             # Session destroy
├── public/
│   ├── css/style.css          # Main stylesheet
│   └── js/app.js              # Frontend JS
└── database/
    └── vikoba.sql             # Full DB schema + seed data
```

---

## ⚙️ Installation

### 1. Requirements
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10+
- Apache with mod_rewrite enabled

### 2. Set up the database
```sql
-- In phpMyAdmin or MySQL CLI:
source /path/to/vikoba/database/vikoba.sql
```

### 3. Configure the app
Edit `config/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'vikoba_db');
define('APP_URL', 'http://localhost/vikoba');
```

### 4. Place in web root
Copy the `vikoba/` folder to your Apache web root:
```
/var/www/html/vikoba/       # Linux
C:\xampp\htdocs\vikoba\     # XAMPP on Windows
```

### 5. Enable mod_rewrite (Ubuntu/Debian)
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 6. Open in browser
```
http://localhost/vikoba
```

---

## 🔑 Default Login Credentials

| Role       | Email                      | Password   |
|------------|---------------------------|------------|
| Admin      | admin@vikoba.co.tz        | password   |
| Treasurer  | treasurer@vikoba.co.tz    | password   |

> **Change these passwords immediately after first login** via Users → Change Password.

---

## 🔒 Security Features
- Password hashing with `password_hash()` (bcrypt)
- PDO prepared statements (SQL injection prevention)
- Session management with timeout
- Role-based access control (Admin / Treasurer / Member)
- XSS protection via `htmlspecialchars()`
- Apache security headers via `.htaccess`

---

## 🌐 AWS EC2 Deployment

```bash
# 1. Connect to your EC2 instance
ssh -i your-key.pem ubuntu@your-ec2-ip

# 2. Install LAMP stack
sudo apt update
sudo apt install apache2 php8.1 php8.1-mysql mysql-server -y

# 3. Upload project files
scp -i your-key.pem -r vikoba/ ubuntu@your-ec2-ip:/var/www/html/

# 4. Import database
mysql -u root -p < /var/www/html/vikoba/database/vikoba.sql

# 5. Set permissions
sudo chown -R www-data:www-data /var/www/html/vikoba
sudo chmod -R 755 /var/www/html/vikoba

# 6. Enable Apache rewrite
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 📋 Features
- ✅ Member registration & profile management
- ✅ Contribution recording with cycles & payment methods
- ✅ Loan application, approval, disbursement
- ✅ Loan repayment tracking with auto-completion
- ✅ Fines & penalties management
- ✅ Financial reports & member summaries
- ✅ User management with role-based access
- ✅ Activity logging
- ✅ Responsive design (Bootstrap 5)
