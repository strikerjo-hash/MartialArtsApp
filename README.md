# 🥋 Martial Arts Studio Management System

A comprehensive PHP/MySQL management system specifically designed for martial arts studios, dojos, and training centers. This system helps you efficiently manage students, memberships, classes, belt rankings, events (including independent registration for belt tests, tournaments, and seminars), attendance, and payments.

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![PHP](https://img.shields.io/badge/PHP-7.4+-purple)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange)
![License](https://img.shields.io/badge/license-MIT-green)

## ✨ Features

### Core Management
- **👥 Student Management**: Complete student profiles with contact information, emergency contacts, and photos
- **📋 Membership System**: Flexible membership plans with multiple durations (monthly, quarterly, annual)
- **🥋 Classes**: Schedule and manage training sessions with instructor assignments
- **🏆 Belt/Rank System**: Track student progression through belt rankings across different martial arts styles
- **✅ Attendance Tracking**: Record student attendance for classes and events

### Event Management (Independent Registration)
- **🎯 Event Types**: Belt tests, tournaments, seminars, workshops, demonstrations
- **📝 Independent Registration**: Events require separate registration from memberships
- **💰 Event Fees**: Set individual registration fees for each event
- **📊 Registration Management**: Track registrations, payments, attendance, and results
- **👨‍🏫 Instructor Assignment**: Assign instructors to specific events
- **📅 Deadlines**: Set registration deadlines and participant limits

### Financial & Reporting
- **💳 Payment Processing**: Track all payments (memberships, events, merchandise)
- **📈 Dashboard Analytics**: Real-time statistics and insights
- **🧾 Receipt Generation**: Automatic receipt numbers for all transactions

### Martial Arts Specific
- **Multiple Styles**: Support for Karate, Taekwondo, Jiu-Jitsu, Muay Thai, Kung Fu, and more
- **Belt Progression**: Customizable belt system for each martial art
- **Training Logs**: Track techniques practiced and progress notes
- **Skill Levels**: Classes categorized by beginner, intermediate, advanced

## 📋 Requirements

- **Web Server**: Apache or Nginx
- **PHP**: Version 7.4 or higher
- **MySQL**: Version 5.7 or higher
- **Extensions**: PDO, PDO_MySQL

## 🚀 Installation

### Step 1: Download and Extract

```bash
# Clone or download the repository
git clone https://github.com/yourusername/martial-arts-studio.git
cd martial-arts-studio
```

### Step 2: Configure Database

1. Create a MySQL database:

```sql
CREATE DATABASE martial_arts_studio;
```

2. Import the database schema:

```bash
mysql -u your_username -p martial_arts_studio < database.sql
```

Or use phpMyAdmin to import `database.sql`

### Step 3: Configure Application

1. Edit `config.php` and update your database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'martial_arts_studio');
```

2. Update the application URL:

```php
define('APP_URL', 'http://yourdomain.com');
```

### Step 4: Set Permissions

```bash
# Create uploads directory and set permissions
mkdir -p uploads/photos
chmod 755 uploads
chmod 755 uploads/photos
```

### Step 5: Access the System

1. Open your browser and navigate to: `http://yourdomain.com/login.php`
2. Use the default credentials:
   - **Username**: `admin`
   - **Password**: `admin123`
3. **Important**: Change the default password immediately after first login!

## 📱 Usage Guide

### Managing Students

1. **Add New Student**:
   - Navigate to "Students" → Click "+ Add Student"
   - Fill in personal information, emergency contacts
   - Set initial status (Active/Inactive/Suspended)

2. **Student Details**:
   - View complete student profile
   - Track belt progression
   - View membership history
   - See event registrations
   - Monitor attendance records

### Creating Events

1. **Create Event**:
   - Go to "Events" → Click "+ Create Event"
   - Choose event type (Belt Test, Tournament, Seminar, etc.)
   - Set date, time, and location
   - Define registration fee and deadline
   - Set maximum participants (optional)
   - Add requirements (minimum belt rank, equipment, etc.)

2. **Manage Registrations**:
   - Click on event to view details
   - Register students individually
   - Track payment status for each registration
   - Record attendance and results
   - Independent of membership status

### Belt Testing Workflow

1. Create a "Belt Test" event
2. Set requirements (e.g., "Minimum 6 months at current rank")
3. Students register independently with registration fee
4. Track attendance on test day
5. Record results (Pass/Fail/Conditional)
6. Promote students who passed:
   - Go to student profile
   - Add new belt achievement
   - System automatically tracks progression

### Managing Classes

1. **Create Class**:
   - Navigate to "Classes"
   - Set day of week and time
   - Assign instructor
   - Set skill level (Beginner/Intermediate/Advanced/All)
   - Define maximum students

2. **Take Attendance**:
   - Go to "Attendance"
   - Select date and class
   - Mark students as Present/Absent/Late/Excused

### Processing Payments

1. **Membership Payment**:
   - When adding/renewing membership
   - System auto-records payment

2. **Event Registration Payment**:
   - Processed during event registration
   - Can be marked as Paid/Pending/Waived
   - Automatic receipt generation

3. **View Payment History**:
   - Navigate to "Payments"
   - Filter by student, type, or date
   - Export reports

## 🗂️ Database Schema

### Key Tables

- **students**: Student personal information and status
- **memberships**: Active and historical membership records
- **events**: All events (belt tests, tournaments, etc.)
- **event_registrations**: Independent event signups
- **belts**: Belt/rank system for each martial art
- **student_belts**: Student belt progression history
- **classes**: Training sessions schedule
- **attendance**: Class and event attendance records
- **payments**: All financial transactions

## 🎨 Customization

### Adding New Martial Art Style

```sql
INSERT INTO martial_arts_styles (name, description) 
VALUES ('Your Style', 'Description of the martial art');
```

Then add belts for that style:

```sql
INSERT INTO belts (style_id, name, color, rank_order, requirements) 
VALUES (6, 'White Belt', 'White', 1, 'Basic stances and blocks');
```

### Customizing Membership Plans

Edit the membership plans in the database or through the settings interface:

```sql
INSERT INTO membership_plans (name, description, duration_months, price, classes_per_week) 
VALUES ('Premium Plan', 'Unlimited classes with personal training', 1, 299.00, 99);
```

## 🔐 Security

- All user inputs are sanitized
- SQL injection protection via prepared statements
- Password hashing using PHP's password_hash()
- Session management for authentication
- CSRF protection recommended for production

### Production Recommendations

1. **Change default password immediately**
2. **Use HTTPS (SSL certificate)**
3. **Regular database backups**
4. **Implement CSRF tokens** (add to forms)
5. **Set strong session settings**
6. **Use environment variables** for sensitive config
7. **Enable error logging** (not display)

## 📊 Reports Available

- Student demographics and status
- Membership revenue by period
- Event participation and revenue
- Attendance statistics
- Belt progression tracking
- Payment history

## 🛠️ Troubleshooting

### Database Connection Issues

```
Error: "Database connection failed"
Solution: Check config.php credentials and MySQL service status
```

### Upload Directory Permissions

```
Error: "Failed to upload file"
Solution: chmod 755 uploads/ and subfolders
```

### Session Issues

```
Error: "Headers already sent"
Solution: Ensure no output before session_start() in config.php
```

## 📝 Default Data

The system comes pre-loaded with:

- **5 Martial Arts Styles**: Karate, Taekwondo, Jiu-Jitsu, Muay Thai, Kung Fu
- **Belt Systems**: Complete belt rankings for Karate and Taekwondo
- **6 Membership Plans**: Various durations and class limits
- **1 Admin User**: Username: admin, Password: admin123

## 🔄 Updates & Maintenance

### Backup Database

```bash
mysqldump -u username -p martial_arts_studio > backup_$(date +%Y%m%d).sql
```

### Update System

1. Backup current files and database
2. Replace files with new version
3. Run any migration scripts
4. Test functionality

## 🤝 Contributing

This is an open-source project. Contributions are welcome!

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## 📄 License

This project is licensed under the MIT License.

```
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## 📧 Support

For issues, questions, or suggestions:
- Create an issue on GitHub
- Email: support@martialartsstudio.com

## 🎯 Roadmap

Future enhancements:
- [ ] Mobile responsive improvements
- [ ] Student/parent portal
- [ ] Email notifications
- [ ] SMS reminders
- [ ] Online registration forms
- [ ] Payment gateway integration
- [ ] QR code check-in
- [ ] Reporting exports (PDF, Excel)
- [ ] Multi-location support
- [ ] Inventory management

---

**Made with 🥋 for Martial Arts Studios**

Version 1.0.0 | Last Updated: February 2026
