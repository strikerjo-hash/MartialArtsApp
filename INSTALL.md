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

## 🚀 What to Build Next

- Create your martial art styles and belt systems
- Add your instructors as users
- Set up weekly class schedule
- Create membership plans matching your pricing
- Schedule your first belt test event!

---

**Full documentation available in README.md**
