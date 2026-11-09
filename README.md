# Kape Agosto POS System with Inventory Forecasting and AI Sales Analytics

Kape Agosto is a web-based point-of-sale and operations system for the shop’s cashiers, managers, and administrators. It manages sales, orders, payments, customers, and inventory, with local sales forecasting and optional AI-generated recommendations. The system is designed to keep core POS and inventory workflows available on the local shop network.

---

## Group Members & Roles

> Replace the placeholders with the group’s official names and assigned roles before final submission.

- **[Group Leader Name]** – Project Manager / Lead Developer
- **[Member 2 Name]** – Frontend / UI-UX Developer
- **[Member 3 Name]** – Backend / Database Administrator

Add or remove rows to match the group.

---

## Tech Stack

- **Frontend:** HTML, CSS, and JavaScript
- **Backend:** PHP
- **Database:** MySQL or MariaDB, accessed through PDO
- **Tools & Version Control:** XAMPP, Apache, Composer, phpMyAdmin, Git, and GitHub
- **Mail:** PHPMailer (installed through Composer)

---

## System Requirements & Prerequisites

- PHP 8.1 or later, with PDO MySQL enabled
- Apache with `mod_rewrite` enabled
- MySQL or MariaDB with InnoDB support
- Composer 2
- Git
- XAMPP is recommended for local Windows development

---

## Installation & Setup Instructions

### 1. Clone the repository

```bash
git clone https://github.com/Renz0202/KAPEAGOSTO-CAPSTONE.git
cd KAPEAGOSTO-CAPSTONE
```

For XAMPP on Windows, clone the repository into `C:\xampp\htdocs\KAPEAGOSTO-CAPSTONE`.

### 2. Install PHP dependencies

```bash
composer install
```

The project is a plain PHP application; it does not use Laravel, Node.js, or `php artisan`.

### 3. Configure the database connection

Open `config/config.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` for your local MySQL/MariaDB server. The database name used by the application is `kape_agosto`. Keep local passwords and API keys out of Git commits.

---

## Database Setup & Configuration

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. In phpMyAdmin, create a database named `kape_agosto` using the `utf8mb4` character set.
3. Import [`database/schema.sql`](database/schema.sql) into `kape_agosto`. It creates the application tables and constraints without importing customer, order, payment, or account records.
4. Import [`database/seed.sql`](database/seed.sql) to add the built-in `admin`, `cashier`, and `manager` roles.
5. Create the first administrator account. Generate a password hash in Command Prompt:

   ```bat
   C:\xampp\php\php.exe -r "echo password_hash('CHOOSE_A_STRONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   In phpMyAdmin’s SQL tab, insert your own username, name, email, and generated hash. Replace the sample values below first:

   ```sql
   INSERT INTO users
     (username, full_name, first_name, last_name, email, password_hash, role_id, status)
   VALUES
     ('your-username', 'Your Name', 'Your First Name', 'Your Last Name',
      'you@example.com', 'PASTE_GENERATED_HASH_HERE', 1, 'active');
   ```

   Do not use the sample values as real credentials or commit your password or hash. The schema-only export contains no default login account.

For an existing installation, see [`public/README.md`](public/README.md) for the one-time migrations required by older database schemas. Do not run those upgrade migrations after importing the current `database/schema.sql` on a fresh installation.

---

## Running the Application

With Apache and MySQL running, open:

```text
http://localhost/KAPEAGOSTO-CAPSTONE/
```

The root `index.php` redirects to the public welcome page. If you clone the repository into a differently named folder, update the application base URL in `config/config.php`.

AI recommendations are optional. Configure a provider key through the application’s AI settings only when you want to use that feature; no API keys are included in the repository.

---

## Default Login Credentials

No default username or password is distributed. Create the first administrator account using the database setup steps above, then create cashier and manager accounts from the application.

---

## Repository

The public GitHub repository is [Renz0202/KAPEAGOSTO-CAPSTONE](https://github.com/Renz0202/KAPEAGOSTO-CAPSTONE).
