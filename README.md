# ATN GIRLS HIGH SCHOOL - Student Result Management System (SRMS)

Welcome to the **Official Student Result Management System** for ATN GIRLS HIGH SCHOOL. This project is a prestige-grade, institutionally-focused web application designed to manage student enrollments, academic records, and merit-based recognition with extreme precision and a high-end aesthetic.

---

## 🏛️ Prestige Aesthetic
The entire system follows the **Prestige Design Language**, characterized by:
- **Deep Navy & Institutional Gold**: A professional color palette that evokes honor and academic excellence.
- **Serif Typography**: Formal honoring of student names using 'Playfair Display' for high-value displays.
- **Glassmorphism Navigation**: A sleek, semi-transparent top navigation bar with blur effects for a modern, high-end feel.
- **Document-Style UI**: Search forms and result displays are designed to look like official registry documents.

---

## 🏆 Key Features

### 1. Merit Recognition System (Hall of Fame)
A signature feature that honors academic brilliance across the institution.
- **Class Toppers**: Automatically identifies and ranks the Top 10 students in each class based on GPA and Total Marks.
- **Subject Geniuses**: Highlights peak performance by identifying students with the highest scores in individual subjects.
- **Archival Access**: Historical merit lists can be filtered by Academic Year and Examination Type (Half Yearly / Final).

### 2. Central Registry Portal (Public View)
The public-facing portal allows students and parents to access results securely.
- **Official Lookup**: A clean, formal search interface to retrieve individual marksheet records.
- **Smart Marksheets**: Context-aware results that automatically hide redundant columns (like "Practical") for junior classes (6-8).
- **Responsive Design**: Fully optimized for mobile lookup, ensuring access on any device.

### 3. Professional Administrative Suite
A robust backend for educators and administrators.
- **Bulk Student Import**: Enroll hundreds of students instantly using prioritized CSV parsing with validation.
- **One-Click Maintenance**: Easily archive entire academic years to keep the primary database fast and responsive.
- **Symmetric Dashboards**: A clean, balanced metric system for tracking active students, result records, and archived items.
- **Role-Based Access**: Specialized views for Administrators and Teachers with secure authentication.

### 4. Result Engine
The core logic of the system.
- **Automated Grading**: Generates GPA and Letter Grades based on custom institutional rules.
- **Global Ranking**: Calculates class-wide positions across all sections for consolidated merit lists.
- **Export Capabilities**: Generate professional PDF Merit Lists for printing or distribution.

---

## 🛠️ Technical Stack
- **Frontend**: HTML5, Vanilla CSS3 (Custom Prestige Design System), JavaScript (ES6+).
- **Backend**: PHP 8.x.
- **Database**: MySQL/MariaDB with PDO for secure object-oriented queries.
- **Design Foundations**: Google Fonts (Inter & Playfair Display), FontAwesome 6.

---

## 🚀 Setup Instructions
1. **Clone the Repository**: Move the project folder into your web server directory (e.g., `xampp/htdocs/`).
2. **Database Setup**:
   - Create a new database in `phpMyAdmin` (e.g., `srms_db`).
   - Import the schema from [database/schema.sql](file:///c:/xampp/htdocs/SRMS/main_2/database/schema.sql).
3. **Configuration**:
   - Update your database credentials in [includes/db_config.php](file:///c:/xampp/htdocs/SRMS/main_2/includes/db_config.php).
4. **Login**: 
   - Access the admin panel at `/admin/login.php`.

---

## 📄 License
This system is developed exclusively for **ATN GIRLS HIGH SCHOOL**. All rights reserved.
