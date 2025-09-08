Let me search for more details about the project's features and modules.Searched project for “modules features inventory parts sku smd tht commission production tracking”3 resultsatte_ms.sql4 relevant sectionstextsrc/classes/Utils/Production/class-ProductionManager.php3 relevant sectionstextsrc/classes/Utils/Production/class-SkuProductionProcessor.phptextNow let me search for more information about the project structure and any documentation.Searched project for “project structure directory folders login authentication system permissions”7 resultsindex.php2 relevant sectionstextconfig/config.phptextpublic_html/components/Login/login.phptextcomposer.locktext.gitignoretextatte_ms.sqltextpublic_html/components/Login/logout.phptextBased on my research of your project files, I can now create a comprehensive README.md file for your inventory management system. Here it is:
ATTE Production Manager
A comprehensive inventory management system built with vanilla PHP, jQuery, and Bootstrap. This system is designed for manufacturing environments to track components, manage production workflows, and handle bill of materials (BOM) for both SMD and THT (through-hole) devices.
Features
Core Inventory Management

Multi-type Component Tracking: Supports Parts, SMD, THT, and SKU inventory management
Real-time Stock Monitoring: Automatic low stock alerts and negative stock tracking
Magazine System: Multi-location warehouse management with sub-magazines
BOM Integration: Bill of Materials support for production planning

Production Management

Production Workflows: Complete production tracking for SMD and THT devices
Commission System: Production order management with state tracking
Quantity Tracking: Real-time production vs. planned quantities
Production Rollback: Support for production corrections and adjustments
Date Tracking: Production date logging and historical tracking

Advanced Features

User Management: Role-based access control with admin permissions
Transfer System: Inter-magazine inventory transfers
Verification Module: Quality control and verification workflows
Archive System: Historical data management
Notification System: Automated alerts and notifications
Google Sheets Integration: Data synchronization with external spreadsheets
FlowPin Integration: External production data import and processing

Technical Features

Database Triggers: Automatic stock level calculations
CRON Jobs: Automated data processing and synchronization
RESTful Architecture: Component-based structure
Responsive Design: Bootstrap 4 responsive interface
Session Management: Secure user authentication

Technology Stack

Backend: PHP 8.0+
Frontend: jQuery 3.6, Bootstrap 4.3.1, Bootstrap Icons
Database: MySQL with InnoDB engine
Dependencies:

Google API Client
HybridAuth for OAuth
Monolog for logging
PHP dotenv for configuration



Requirements

PHP 8.0 or higher
MySQL 5.7 or higher
Web server (Apache/Nginx)
Composer for dependency management
Required PHP extensions:

PDO
cURL
ext-json
ext-mbstring



Installation

Clone the repository
bashgit clone [repository-url]
cd production-manager-atte

Install dependencies
bashcomposer install

Configure environment

Copy .env.example to .env
Update database credentials and other settings
Set BASEURL to your domain


Database setup
bashmysql -u your_username -p your_database < atte_ms.sql

Configure web server

Point document root to public_html/ directory
Ensure .htaccess rules are enabled for Apache
Configure PHP to include config/config.php globally


Set up Google Sheets integration (optional)

Configure Google OAuth credentials in .env
Set up Google Sheets API access



Directory Structure
├── config/                 # Configuration files
├── public_html/           # Web root directory
│   ├── assets/           # CSS, JS, images
│   ├── components/       # Application modules
│   └── index.php        # Main entry point
├── src/                  # Core application classes
│   ├── classes/         # PHP classes and utilities
│   └── cron/           # Scheduled tasks
├── vendor/              # Composer dependencies
├── atte_ms.sql         # Database schema
└── composer.json       # Dependency configuration
Key Modules
Production Management

SMD Production: Surface-mount device production tracking
THT Production: Through-hole technology production tracking
Production Manager: Core production processing logic

Inventory Control

Warehouse: Stock level management and monitoring
Transfer: Inter-location inventory movement
Verification: Quality control workflows

Administration

BOM Management: Bill of materials upload and editing
Component Management: Parts and device catalog management
User Management: Access control and permissions
Magazine Management: Warehouse location configuration

Usage
Basic Workflow

Login: Access the system with your credentials
View Dashboard: See active commissions and current tasks
Production: Record production activities for SMD/THT devices
Inventory: Monitor stock levels and manage transfers
Commissions: Track production orders and completion status

Production Process

Create or assign production commissions
Check component availability via BOM
Record production quantities
System automatically deducts components from inventory
Track production progress and completion

Inventory Management

Monitor stock levels across all magazines
Receive automatic alerts for low stock items
Transfer inventory between locations
Verify inventory accuracy through verification module

Configuration
Key configuration files:

.env - Environment variables and credentials
config/config.php - Core application configuration
config/config-google-sheets.php - Google Sheets integration

Database Schema
The system uses a comprehensive database schema with the following key tables:

inventory__* - Inventory tracking for different component types
list__* - Master data for components, devices, and materials
bom__* - Bill of materials definitions
commission__* - Production order management
user - User management and permissions

Security Features

SHA-256 password hashing
Session-based authentication
Role-based access control
SQL injection protection via prepared statements
CSRF protection

Support
For technical support or questions about the system, please contact the development team or create an issue in the project repository.

Note: This is a production inventory management system designed for manufacturing environments. Ensure proper backup procedures and testing before deploying in production.RetryClaude can make mistakes. Please double-check responses.
