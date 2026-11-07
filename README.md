# MITCH COSMETICS SHOP — Management System

**Business:** MITCH COSMETICS SHOP  
**Location:** Paynesville, Montserrado County, Liberia  
**Stack:** PHP 8+ · MySQL · Bootstrap 5 · Vanilla JS  
**Hosting:** Compatible with InfinityFree shared hosting (no SSH, no Composer)

---

## Features

| Module | Description |
|---|---|
| Authentication | Login / Register with 3 roles: Admin, Staff, Customer |
| Admin Dashboard | Stats cards, monthly sales chart, top-products donut chart, low-stock alerts |
| Product Inventory | Add/Edit/Delete products, image upload, video upload, SKU/barcode auto-generation |
| Categories | Full CRUD for product categories |
| Point of Sale (POS) | Product search/scan, cart, discounts (staff capped at 10%; admin override), payment methods, change calculation |
| Receipt Printing | Printable receipt with business info, items, totals, signature lines |
| Order Management | Place/view/update orders, status workflow (Pending → Processing → Completed) |
| Customer Module | Browse shop, product gallery, place orders, order history, profile |
| Staff Management | Add/edit/delete staff accounts |
| Attendance System | Staff check-in/check-out, daily & monthly history, admin reports |
| Reports | Sales, Inventory, Orders, Customers, Staff, Attendance, Profit, Product List |
| CSV Export | Export any report to CSV in-browser |
| Calculator | Built-in calculator with keyboard support + discount calculator |
| Security | Password hashing (bcrypt), PDO prepared statements, CSRF tokens, session timeout |

---

## Demo Accounts

| Role | Email | Password |
|---|---|---|
| Administrator | admin@mitchcosmetics.com | password |
| Staff | staff@mitchcosmetics.com | password |
| Customer | mitchfredick@gmail.com | password |

> **Important:** Change all passwords immediately after installation.

---

## Installation

### Local (XAMPP / WAMP / Laragon)

1. **Copy files**  
   Place the `mitch-cosmetics` folder inside your web root:  
   - XAMPP → `C:\xampp\htdocs\mitch-cosmetics`  
   - WAMP  → `C:\wamp64\www\mitch-cosmetics`

2. **Create the database**  
   - Open phpMyAdmin → create a new database named `mitch_cosmetics`  
   - Select it → click **Import** → choose `mitch_cosmetics.sql` → click **Go**

3. **Configure the connection**  
   Open `includes/config.php` and set:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'mitch_cosmetics');
   define('DB_USER', 'root');   // your MySQL username
   define('DB_PASS', '');       // your MySQL password
   ```

4. **Visit the site**  
   Open your browser: `http://localhost/mitch-cosmetics/`

---

### InfinityFree (Free Hosting)

1. **Create a free account** at [infinityfree.net](https://infinityfree.net)

2. **Create a hosting account** and note your:
   - MySQL hostname (e.g. `sql301.infinityfree.net`)
   - Database name (auto-assigned, e.g. `if0_12345678_mitch_cosmetics`)
   - Database username and password

3. **Create the database**  
   Go to **Control Panel → MySQL Databases** → create a new database  
   Then go to **phpMyAdmin** for that database → **Import** → select `mitch_cosmetics.sql`

4. **Edit config.php** with your InfinityFree credentials:
   ```php
   define('DB_HOST', 'sql301.infinityfree.net'); // your actual host
   define('DB_NAME', 'if0_12345678_mitch');       // your actual DB name
   define('DB_USER', 'if0_12345678');             // your actual username
   define('DB_PASS', 'YourPassword');             // your password
   ```

5. **Upload files**  
   Use the **File Manager** in your InfinityFree control panel or an FTP client (FileZilla):
   - Upload all files inside `mitch-cosmetics/` to your `htdocs/` folder
   - Make sure `assets/images/products/` and `assets/videos/` are writable (chmod 755)

6. **Visit your site**  
   `http://yourdomain.infinityfreeapp.com/`

---

## File Structure

```
mitch-cosmetics/
├── index.php               ← Entry point (auto-redirects by role)
├── login.php               ← Login page
├── register.php            ← Customer registration
├── logout.php              ← Destroys session
├── unauthorized.php        ← 403-style page
├── mitch_cosmetics.sql     ← Full database schema + seed data
│
├── includes/
│   ├── config.php          ← DB credentials & app settings
│   ├── db.php              ← PDO connection singleton
│   ├── session.php         ← Session helpers, CSRF, role guards
│   ├── functions.php       ← Global helper functions
│   ├── header.php          ← Sidebar + top bar layout
│   └── footer.php          ← Bootstrap JS + app.js
│
├── admin/
│   ├── dashboard.php       ← Admin dashboard
│   ├── products.php        ← Product CRUD + image/video upload
│   ├── categories.php      ← Category management
│   ├── orders.php          ← Order management + status update
│   ├── sales.php           ← Sales history + receipt view
│   ├── customers.php       ← Customer list + activate/deactivate
│   ├── staff.php           ← Staff management
│   ├── attendance.php      ← Staff attendance report (admin view)
│   └── profile.php         ← Admin/staff profile & password change
│
├── staff/
│   ├── dashboard.php       ← Staff dashboard
│   ├── pos.php             ← Point of Sale system
│   ├── receipt.php         ← Print receipt after sale
│   ├── attendance.php      ← Staff check-in/check-out
│   ├── calculator.php      ← Built-in calculator
│   └── profile.php         ← Redirects to admin/profile.php
│
├── customer/
│   ├── dashboard.php       ← Customer home
│   ├── shop.php            ← Browse & order products
│   ├── gallery.php         ← Visual product gallery
│   ├── orders.php          ← Order history & tracking
│   └── profile.php         ← Edit profile & change password
│
├── reports/
│   └── index.php           ← All reports (sales/inventory/staff/etc.)
│
└── assets/
    ├── css/style.css       ← Custom CSS (Bootstrap 5 extended)
    ├── js/app.js           ← Calculator, POS cart, helpers
    ├── images/
    │   ├── products/       ← Uploaded product images
    │   └── banners/        ← Banner images
    └── videos/             ← Uploaded product videos
```

---

## Customization

### Change Business Info
Edit `includes/config.php`:
```php
define('APP_NAME',    'MITCH COSMETICS SHOP');
define('APP_ADDRESS', 'Paynesville, Montserrado County, Liberia');
define('APP_PHONE',   '+231 000 000 000');
define('APP_EMAIL',   'info@mitchcosmetics.com');
define('APP_CURRENCY', '$');
define('APP_LOW_STOCK_THRESHOLD', 10);  // Alert when stock ≤ this
```

### Change Brand Colors
Edit `assets/css/style.css`, update the `:root` variables:
```css
:root {
  --primary:     #c0007a;   /* Main pink/magenta */
  --secondary:   #ff6fb5;   /* Light pink */
  --sidebar-bg:  #1a0a14;   /* Dark sidebar */
}
```

---

## Security Notes

- All SQL queries use PDO prepared statements (no SQL injection)
- Passwords are hashed with `bcrypt` (cost 12)
- CSRF tokens protect all POST forms
- Sessions expire after 1 hour of inactivity
- Role-based access control on every protected page
- File uploads are validated by MIME type, not just extension

---

## InfinityFree Compatibility Notes

- No Composer, no Node.js, no SSH required
- All CDN assets (Bootstrap 5, Bootstrap Icons, Chart.js) loaded from jsDelivr
- PHP sessions work normally on InfinityFree
- File upload size is limited by InfinityFree's PHP `upload_max_filesize` (typically 10MB)
- Uses `utf8mb4` charset — compatible with InfinityFree MySQL

---

## Support

For issues or customizations, contact the development team.  
© 2024–2026 MITCH COSMETICS SHOP — All Rights Reserved.
