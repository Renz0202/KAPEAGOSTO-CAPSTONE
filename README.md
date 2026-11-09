# Kape Agosto POS System with Inventory Forecasting and AI Sales Analytics

Kape Agosto is a web-based point-of-sale and operations system for the shop’s cashiers, managers, and administrators. It manages sales, orders, payments, customers, and inventory, with local sales forecasting and optional AI-generated recommendations. The system is designed to keep core POS and inventory workflows available on the local shop network.

---

## Group Members & Roles

- **Castro** – Project Manager / Lead Developer
- **Castro, Biteranta** – Frontend / UI-UX Developer
- **Castro** – Backend / Database Administrator
- **Bayawa, Cariño, Permaran** – Documentation

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

Open Command Prompt or PowerShell in `C:\xampp\htdocs`, then run:

```bash
git clone https://github.com/Renz0202/KAPEAGOSTO-CAPSTONE.git
cd KAPEAGOSTO-CAPSTONE
```

This places the project in `C:\xampp\htdocs\KAPEAGOSTO-CAPSTONE`, where XAMPP Apache can serve it.

### 2. Install PHP dependencies

```bash
composer install
```

If `composer` is not recognized, install Composer 2 for Windows, reopen the terminal, and run the command again. The project is plain PHP; it does not use Laravel, Node.js, or `php artisan`.

### 3. Configure the database connection

Open `config/config.php` and check `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`. The XAMPP defaults in this project are:

| Setting | Local value |
| --- | --- |
| `DB_HOST` | `localhost` |
| `DB_NAME` | `kape_agosto` |
| `DB_USER` | `root` |
| `DB_PASS` | Empty, unless you set a MySQL root password |

Keep local passwords and API keys out of Git commits. Do not use an empty database password on a network-facing server.

---

## Database Setup & Configuration

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open [phpMyAdmin](http://localhost/phpmyadmin) and create/select a database named `kape_agosto` with the `utf8mb4` character set.
3. Select `kape_agosto`, open **Import**, choose [`database/schema.sql`](database/schema.sql), then click **Import**. This creates 24 application tables and their constraints and triggers; it contains no customer, order, or payment records.
4. With `kape_agosto` still selected, import [`database/seed.sql`](database/seed.sql). It adds the built-in roles and one demo administrator account.
5. If your MySQL connection details differ from the XAMPP defaults above, update them in `config/config.php`.
6. Start the application and sign in with the demo account below. The clean database has no product or sales history yet; add catalog and inventory records in the app. Change the demo password before using real data or exposing the system beyond a local demo.

For an existing installation, see [`public/README.md`](public/README.md) for the one-time migrations required by older database schemas. Do not run those upgrade migrations after importing the current `database/schema.sql` on a fresh installation.

---

## Running the Application

With Apache and MySQL running, open the project folder URL:

```text
http://localhost/KAPEAGOSTO-CAPSTONE/
```

The root `index.php` redirects to the public welcome page. If you use a different folder name under `C:\xampp\htdocs`, replace `KAPEAGOSTO-CAPSTONE` in the URL with that folder name. For example, the current workspace folder uses `http://localhost/KAPEAGOSTO/`.

AI recommendations are optional. Configure a provider key through the application’s AI settings only when you want to use that feature; no API keys are included in the repository.

---

## Default Login Credentials

The seed file creates this local demo administrator:

- **Admin email / username:** `admin`
- **Password:** `Admin@123`

This shared demo password is included only to make local evaluation easier. Change it before using the application with real data or exposing it to a network.

---

## Repository

The public GitHub repository is [Renz0202/KAPEAGOSTO-CAPSTONE](https://github.com/Renz0202/KAPEAGOSTO-CAPSTONE).
