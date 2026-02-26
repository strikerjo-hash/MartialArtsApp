# ⚡ QUICK START GUIDE

## 5-Minute Installation

### 1️⃣ Setup Database (2 minutes)

```bash
# Create database
mysql -u root -p
CREATE DATABASE martial_arts_studio;
EXIT;

# Import schema
mysql -u root -p martial_arts_studio < database.sql
```

### 2️⃣ Configure (1 minute)

Edit `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Your MySQL username
define('DB_PASS', '');              // Your MySQL password
define('DB_NAME', 'martial_arts_studio');
```

### 3️⃣ Set Permissions (30 seconds)

```bash
mkdir -p uploads/photos
chmod 755 uploads uploads/photos
```

### 4️⃣ Access System (30 seconds)

1. Place files in your web server directory (htdocs, www, public_html)
2. Open browser: `http://localhost/martial-arts-studio/login.php`
3. Login with:
   - Username: `admin`
   - Password: `admin123`

### 5️⃣ First Steps (1 minute)

✅ Change default admin password
✅ Add your first student
✅ Create a membership plan
✅ Schedule your first class
✅ Create a belt test event

## 🎯 Key Features

### Independent Event Registration
Events (belt tests, tournaments, seminars) work independently from memberships:
- Students can register for events even without active membership
- Each event has its own registration fee
- Track payments separately from membership dues
- Record attendance and results per event

### Belt System
- Pre-configured for Karate & Taekwondo
- Easy to add more martial arts styles
- Automatic progression tracking
- Belt test events link directly to promotions

### Flexible Memberships
- Monthly, quarterly, and annual plans
- Unlimited or limited classes per week
- Easy renewal tracking
- Payment history

## 📞 Need Help?

1. **Can't connect to database?**
   - Check MySQL is running
   - Verify credentials in config.php
   - Ensure database was created

2. **Can't upload files?**
   - Check uploads/ folder exists
   - Verify folder permissions (755)

3. **Getting PHP errors?**
   - PHP 7.4+ required
   - Enable PDO and PDO_MySQL extensions

## 📊 Report Export Dependencies (Optional)

To enable PDF and Excel report export, install the required libraries:

### DomPDF (for PDF export)

```bash
cd libs
curl -L -o dompdf.zip https://github.com/dompdf/dompdf/archive/refs/tags/v2.0.8.zip
unzip dompdf.zip && mv dompdf-2.0.8 dompdf && rm dompdf.zip

curl -L -o fontlib.zip https://github.com/dompdf/php-font-lib/archive/refs/tags/0.5.6.zip
unzip fontlib.zip && mv php-font-lib-0.5.6 php-font-lib && rm fontlib.zip

curl -L -o svglib.zip https://github.com/dompdf/php-svg-lib/archive/refs/tags/0.5.4.zip
unzip svglib.zip && mv php-svg-lib-0.5.4 php-svg-lib && rm svglib.zip

curl -L -o html5.zip https://github.com/Masterminds/html5-php/archive/refs/tags/2.9.0.zip
unzip html5.zip && mv html5-php-2.9.0 html5-php && rm html5.zip
cd ..
```

### PhpSpreadsheet (for Excel export)

Requires [Composer](https://getcomposer.org/download/). Run from the project root:

```bash
composer install
```

> Both libraries are optional. Reports work normally without them; only the PDF/Excel download buttons require them.

## 🚀 What to Build Next

- Create your martial art styles and belt systems
- Add your instructors as users
- Set up weekly class schedule
- Create membership plans matching your pricing
- Schedule your first belt test event!

---

**Full documentation available in README.md**
