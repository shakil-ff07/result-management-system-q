# ATN GIRLS HIGH SCHOOL - Student Result Management System (SRMS)

Welcome to the **Official Student Result Management System** for ATN GIRLS HIGH SCHOOL. This is a comprehensive, prestige-grade web application designed to manage student enrollments, academic records, examinations, and merit-based recognition with precision and a high-end aesthetic.

---

## 🏛️ Prestige Design Language

The entire system follows a sophisticated **Prestige Design Language**, characterized by:
- **Deep Navy & Institutional Gold**: Professional color palette evoking honor and academic excellence
- **Serif Typography**: Formal 'Playfair Display' font for high-value displays
- **Glassmorphism Navigation**: Sleek, semi-transparent navigation with blur effects
- **Document-Style UI**: Search forms and result displays designed like official registry documents
- **Dark/Light Theme Support**: User-toggleable themes with persistent preferences
- **Responsive Design**: Fully optimized for mobile, tablet, and desktop devices

---

## 🏆 Core Features

### 1. Public Portal (Student/Parent Access)

#### 📋 Result Lookup System
- **Secure Authentication**: Students access results using Roll Number, Date of Birth, Class, Section, and Exam Type
- **Smart Marksheets**: Automatically adapts display based on class level (hides practical/SQ columns for junior classes)
- **Professional PDF Export**: Download printable marksheet with official formatting
- **QR Code Verification**: Each marksheet includes a unique QR code for authenticity verification
- **Real-time Validation**: Instant feedback on invalid credentials or unpublished results

#### 🏅 Hall of Fame (Merit Recognition)
- **Class Toppers**: Ranks Top 10 students per class based on GPA and Total Marks
- **Subject Geniuses**: Highlights highest scorers in individual subjects
- **Smart Calendar Logic**: Auto-selects current exam session based on calendar month
- **Historical Archives**: Filter merit lists by Academic Year and Examination Type
- **Elegant Display**: Premium card-based layout with gold accents for achievers

#### 🎫 Admit Card Portal
- **Controlled Release**: Admin-controlled publish/unpublish functionality
- **Session Selection**: Choose academic year and exam type (Half Yearly/Final)
- **Professional Layout**: Official admit card format ready for printing
- **Photo & Signature Support**: Displays student information in formal document style

#### 🔐 Result Verification Portal
- **Token-Based Security**: Unique verification tokens generated for each marksheet
- **Public Verification**: Anyone can verify result authenticity via secure URL
- **Audit Trail**: Tracks verification view count for security monitoring
- **Tamper-Proof Design**: Side-by-side comparison of original vs verified data

---

### 2. Administrative Suite

#### 📊 Dashboard & Analytics
- **Real-Time Metrics**: Live counts of students, classes, sections, and results
- **Visual Analytics**: Interactive charts showing pass rates, grade distributions, subject performance
- **KPI Cards**: Quick-view statistics with prestigious styling
- **Year-over-Year Comparison**: Track institutional performance across academic sessions

#### 👥 Student Management
- **Comprehensive Profiles**: Store name, DOB, parent info, phone, group, elective subjects
- **Bulk Import**: CSV upload for enrolling hundreds of students instantly
- **Smart CSV Parsing**: Validates data, handles optional subjects, assigns groups automatically
- **Inline Editing**: Quick updates to student information without full page reloads
- **Group Assignment**: Manage Science/Arts/Commerce streams for Classes 9-10
- **Optional Subject Logic**: Automatic pairing (e.g., Biology ↔ Higher Math, Geography ↔ Economics)

#### 📚 Class & Subject Management
- **Multi-Year Support**: Organize classes by academic year with sections
- **Subject Configuration**: Define compulsory, optional, and school-based subjects
- **Marks Distribution**: Configure CQ, MCQ, SQ, and Practical marks per subject
- **Teacher Assignments**: Link teachers to specific class-subject combinations

#### ✍️ Marks Entry System
- **Role-Based Access**: Teachers see only assigned classes/subjects; Admins see all
- **Flexible Entry Modes**: Enter CQ, MCQ, SQ, and Practical marks separately
- **Auto-Calculation**: Computes total marks, grades, and GPA in real-time
- **Progress Tracking**: Visual indicators show completed vs pending entries
- **Lock/Unlock Controls**: Admin can enable/disable marks entry globally
- **Smart Navigation**: Step-by-step wizard (Year → Class → Section → Subject → Students)

#### 🎯 Result Generation Engine
- **Automated Grading**: Applies institutional grading rules consistently
- **GPA Calculation**: Computes grade points based on subject performance
- **Status Determination**: Auto-marks Pass/Fail based on minimum thresholds
- **Batch Processing**: Generate results for entire classes at once
- **Error Handling**: Validates data integrity before finalizing results

#### 📈 Print & Reports
- **Result Sheets**: Professional class-wise mark sheets for printing
- **Mark Analysis**: Subject-wise performance breakdowns
- **Student Details**: Comprehensive individual student reports
- **Export Formats**: PDF and CSV download options

#### 🔄 Student Promotion System
- **Batch Promotion**: Move entire classes to next academic year
- **Auto-Distribution**: Intelligently assign promoted students to new sections
- **Roll Number Reset**: Option to reset roll numbers for new session
- **Merit-Based Sorting**: Promote based on Final Exam GPA and total marks
- **Stream Management**: Handle Science/Arts/Commerce transitions for Class 9→10

#### 👨‍🏫 Teacher Management
- **Assignment Tracking**: Monitor which teachers have entered marks
- **Status Overview**: See completion percentage per teacher/class/subject
- **Access Control**: Restrict teachers to their assigned responsibilities only

#### 🔒 Superadmin Portal
- **Headmaster Management**: Create and manage headmaster accounts
- **System Oversight**: Highest-level administrative controls
- **Institutional Settings**: Configure school-wide parameters

---

### 3. System Administration

#### ⚙️ System Settings
- **Marks Entry Toggle**: Enable/disable marks entry globally with custom lock message
- **Admit Card Control**: Publish/unpublish admit cards with session configuration
- **Academic Year Management**: Set current admit year and exam type
- **Maintenance Mode**: Temporarily restrict access during updates

#### 📝 Activity Logs
- **Comprehensive Auditing**: Track all admin actions with timestamps
- **User Attribution**: Log which admin performed each action
- **Search & Filter**: Find specific actions by user, action type, or date
- **Log Cleanup**: Auto-delete logs older than 30 days or manual deletion
- **Pagination**: Efficient browsing through thousands of log entries

#### 🗄️ Database Maintenance
- **Archive System**: Move old academic years to archive tables
- **Performance Optimization**: Keep active database lean and fast
- **Data Integrity Checks**: Validate relationships before operations

---

## 🛠️ Technical Stack

### Frontend
- **HTML5** with semantic structure
- **Vanilla CSS3** with Custom Properties (CSS Variables)
- **JavaScript ES6+** for dynamic interactions
- **Bootstrap 5.3** for responsive grid and components
- **FontAwesome 6** for iconography
- **Google Fonts**: Inter (UI), Playfair Display (headings), Noto Sans Bengali (localization)
- **Flatpickr** for date selection
- **ApexCharts** for analytics visualizations
- **SweetAlert2** for premium alerts and confirmations

### Backend
- **PHP 8.x** with strict typing
- **PDO** for secure database queries
- **MySQL/MariaDB** with InnoDB engine
- **Session Management** with role-based authentication
- **Token Generation** for secure verification links

### Architecture
- **MVC-Inspired Structure**: Separation of concerns between views, logic, and data
- **Helper Functions**: Reusable utilities for language, tokens, and common operations
- **Configuration Files**: Centralized database and system settings
- **JSON Configuration**: External subject/marks distribution configs

---

## 🚀 Installation & Setup

### Prerequisites
- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache/Nginx)
- Composer (optional, for dependencies)

### Step-by-Step Installation

1. **Clone or Download Repository**
   ```bash
   git clone <repository-url>
   # OR download and extract ZIP
   ```

2. **Move to Web Server**
   - Copy project folder to your web root (e.g., `htdocs/`, `www/`, `/var/www/html/`)

3. **Database Setup**
   - Create a new database in phpMyAdmin or MySQL CLI
   - Import the schema from `database/schema.sql`
   - Update database credentials in `includes/db_config.php`:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'your_database_name');
     define('DB_USER', 'your_username');
     define('DB_PASS', 'your_password');
     ```

4. **Configure System Settings**
   - Access `/admin/login.php` with default credentials (check documentation or database)
   - Navigate to System Settings to configure:
     - Current academic year
     - Exam types (Half Yearly / Final)
     - Marks entry permissions
     - Admit card publication status

5. **Initial Data Population**
   - Use Admin Panel → Manage Classes to create class structures
   - Use Admin Panel → Manage Subjects to define subject catalog
   - Use Bulk Import or Add Student to enroll students
   - Assign teachers to class-subject combinations

6. **Logo & Branding**
   - Replace `logo/logo.png` with your institution's logo
   - Update school name in relevant header files if needed

---

## 📁 Project Structure

```
/workspace
├── index.php                 # Public home/result lookup portal
├── result.php                # Individual marksheet display
├── hall-of-fame.php          # Merit list showcase
├── admit-card.php            # Admit card search & preview
├── print-admit-card.php      # Printable admit card generator
├── verify.php                # Secure result verification page
├── ping.php                  # Health check endpoint
├── includes/                 # Shared utilities
│   ├── db_config.php         # Database connection
│   ├── token_helper.php      # Marksheet token generation
│   └── lang_helper.php       # Localization functions
├── admin/                    # Administrator panel
│   ├── dashboard.php         # Admin overview
│   ├── analytics.php         # Performance charts
│   ├── manage-students.php   # Student CRUD operations
│   ├── bulk-import-students.php  # CSV import tool
│   ├── marks-entry.php       # Teacher/Admin marks input
│   ├── generate-results.php  # Result compilation engine
│   ├── promote-students.php  # Year-end promotion tool
│   ├── teacher-marks-status.php  # Teacher progress tracking
│   ├── activity-logs.php     # Audit trail viewer
│   ├── system-settings.php   # Global configuration
│   └── ... (other modules)
├── superadmin/               # Headmaster-level controls
│   ├── dashboard.php
│   ├── manage-headmasters.php
│   └── ...
├── database/                 # SQL schemas and seeds
├── json/                     # Configuration JSON files
└── logo/                     # Institutional branding
```

---

## 🔐 Security Features

- **Prepared Statements**: All database queries use PDO prepared statements
- **Session-Based Auth**: Secure admin/teacher authentication with timeout
- **Role-Based Access Control (RBAC)**: Separate permissions for Admin, Teacher, Superadmin
- **Input Validation**: Server-side validation on all user inputs
- **XSS Protection**: HTML escaping on all output
- **CSRF Considerations**: Form submission validation
- **Verification Tokens**: Cryptographically secure random tokens for marksheet verification
- **Activity Logging**: Complete audit trail of administrative actions

---

## 🎯 Key Workflows

### For Students/Parents:
1. Visit homepage → Select Class, Section, Year, Exam
2. Enter Roll Number and Date of Birth
3. View detailed marksheet with grades and GPA
4. Download PDF or verify authenticity via QR code

### For Teachers:
1. Login to Admin Panel
2. Navigate to Marks Entry
3. Select assigned class and subject
4. Enter marks for each student (CQ, MCQ, SQ, Practical)
5. System auto-calculates totals and grades
6. Submit for result generation

### For Administrators:
1. Setup academic session (classes, subjects, students)
2. Assign teachers to subjects
3. Enable marks entry period
4. Monitor teacher progress via status dashboard
5. Generate and publish results
6. Print official documents (marksheets, admit cards)
7. Promote students to next academic year
8. Archive completed sessions

---

## 📄 License

This system is developed exclusively for **ATN GIRLS HIGH SCHOOL**. All rights reserved.

---

## 🤝 Support & Credits

**Developed with ❤️ for educational excellence**

For technical support or feature requests, please contact the system administrator.

---

## 📌 Recent Updates

- ✅ Added Dark/Light theme toggle with localStorage persistence
- ✅ Implemented QR code verification for all marksheets
- ✅ Enhanced Hall of Fame with calendar-based auto-selection
- ✅ Built comprehensive Activity Log system with cleanup
- ✅ Added Student Promotion with auto-distribution logic
- ✅ Integrated Analytics Dashboard with ApexCharts
- ✅ Improved Marks Entry with role-based filtering
- ✅ Added Bulk Student Import with intelligent group assignment
- ✅ Implemented System Settings for global toggles
- ✅ Enhanced Admit Card system with admin-controlled publishing
