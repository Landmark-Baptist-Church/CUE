# Cue: Worship Planning & Scheduling Suite

**Cue** is a lightning-fast, enterprise-grade worship planning and scheduling application designed for speed, reliability, and seamless communication. Built on a lightweight PHP/AJAX backend, it bypasses heavy frameworks to deliver instant drag-and-drop UI interactions, robust conflict detection, and native SMTP email distribution.

The entire suite is strictly secured using **Authentik reverse-proxy headers** for granular Role-Based Access Control (RBAC), ensuring safe read-only fallbacks for standard volunteers while locking down editing capabilities for administrators.

---

## 🚀 Key Features

### 📝 Service Builder
* **Drag-and-Drop Interface:** Build orders of service instantly using `SortableJS`.
* **Smart Suggestions:** Automatically suggests Opener, Choir Special, and Special Music based on the Master Schedule for that specific date.
* **Layout Templates:** Save complex service structures as reusable templates with preserved color-coding and labels.
* **Auto-Logging:** One-click logging updates the "Last Used" date for all hymns and prelude sets in the service.
* **Print-Ready Cue Cards:** Generates clean, high-contrast, distraction-free HTML cue cards for the platform and A/V booth.

### 📅 Master Schedule
* **Visual Calendar:** Powered by `FullCalendar.js` for a top-down view of the month.
* **Conflict Prevention:** Automatically warns if a group (or shared member) is over-scheduled or within a 14-day lock window.
* **Role Assignments:** Inline scheduling for Pianists, Offertories, Choir Openers, and Choir Specials.
* **Custom Events:** Add special services (e.g., "Missions Conference") on the fly.

### 🎵 Master Hymn Tracker
* **Inline Spreadsheet Editor:** Edit titles, keys, and metrics instantly without page reloads.
* **Usage Analytics:** Tracks the `Date_of_Most_Recent_Use` so you can avoid over-playing songs or easily find the "dusty" ones.
* **Quick PDF Access:** Instantly open the associated sheet music PDF directly from the search results or tracker.

### 👥 Groups & Roster Management
* **Centralized Database:** Manage individuals, contact info, and their group memberships.
* **Distribution Lists:** Build custom email lists for seamless team communication.
* **Tabbed Interface:** Effortlessly switch between managing musical ensembles and communication lists.

### 🎹 Prelude Builder
* **Numerical Sequencing:** Build prelude sets based strictly on hymnal numbers.
* **Nearest-Neighbor Suggestions:** Select a hymn and instantly see the 5 preceding and 5 following songs in the physical hymnal for smooth page-turning.

### ✉️ Native SMTP Email Engine
* **Zero-Dependency Sockets:** Bypasses PHP's finicky `mail()` function by opening a direct socket to a local SMTP server (e.g., Stalwart on port 25).
* **Live Schedule Generation:** Automatically compiles the month's schedule or a specific service's cue card into a beautiful, mobile-friendly HTML table injected right into the email body.
* **Direct Attachments:** Supports `multipart/form-data` to blast sheet music, PDFs, or MP3s to your groups and lists.
* **Dynamic Reply-To:** Injects the active user's Authentik email address into the `Reply-To` header, ensuring responses go directly to the sender.

---

## 🔒 Security & RBAC (Authentik)

Cue does not have a login screen. It is designed to sit behind an **Nginx Reverse Proxy** integrated with **Authentik** (Forward Auth). 

When a user accesses the site, Nginx verifies their identity with Authentik and injects their Username, Email, and Group memberships into the HTTP headers. PHP reads these trusted headers to apply granular RBAC.

### Access Levels
All authenticated users are granted **Read-Only** access to the Builder, Schedule, Hymns, Groups, and Preludes pages. Editing capabilities are strictly locked behind the following Authentik groups:

| Authentik Group | Granted Permissions |
| :--- | :--- |
| `n-cue-cuecards` | Add, edit, reorder, and delete items in the Service Builder. |
| `n-cue-savetemplates` | Save and delete Service Builder layout templates. |
| `n-cue-schedule` | Modify calendar events, special music assignments, and pianists. |
| `n-cue-hymnsedit` | Inline-edit the Master Hymn database. |
| `n-cue-groups` | Create/Delete people and groups, and modify rosters. |
| `n-cue-emaillists` | Create/Delete email distribution lists and manage subscribers. |
| `n-cue-preludes` | Build, reorder, and delete Prelude Sets. |
| `n-cue-emails` | Send HTML email blasts and attachments via the SMTP engine. |
| `n-cue-config` | Access the restricted Configuration dashboard (Color styling & Capacities). |

---

## 🛠 Tech Stack

* **Backend:** PHP 8.x
* **Database:** SQLite (PDO)
* **Frontend:** TailwindCSS (CDN), Vanilla JavaScript
* **Libraries:** SortableJS (Drag & Drop), FullCalendar.js (Master Schedule)

---

## ⚙️ Deployment & Server Configuration

### 1. File Structure Setup
Ensure the web root has write permissions to the SQLite database file (`cue.sqlite` or similar, defined in `db.php`).

### 2. Nginx Forward-Auth Configuration
To secure the application and pass the required headers to PHP, your Nginx server block should utilize the following forward-auth pattern:

```nginx
# Request authorization from Authentik
auth_request /outpost.goauthentik.io/auth/nginx;
error_page 401 = @goauthentik_proxy_signin;

# Pass trusted Authentik data to the PHP backend
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass 127.0.0.1:8080; # or unix:/var/run/php-fpm.sock
    
    fastcgi_param HTTP_X_AUTHENTIK_USERNAME $upstream_http_x_authentik_username;
    fastcgi_param HTTP_X_AUTHENTIK_EMAIL $upstream_http_x_authentik_email;
    fastcgi_param HTTP_X_AUTHENTIK_GROUPS $upstream_http_x_authentik_groups;
}

location @goauthentik_proxy_signin {
    internal;
    add_header Set-Cookie $auth_cookie;
    return 302 /outpost.goauthentik.io/start?rd=https://$host$request_uri;
}
```

### 3. SMTP Configuration
The email engine is hardcoded to look for an unauthenticated local SMTP relay on port 25. 
To adjust this, locate the SMTP configuration block inside `index.php`, `schedule.php`, and `groups.php`:
```php
$smtpHost = '127.0.0.1';
$smtpPort = 25;
$from = 'noreply@yourdomain.com';
```

### 4. Remove Mock Headers
**Important:** During development, Mock Authentik headers are placed at the top of the main PHP files. **You must delete the Mock Auth Block** before going to production, or it will override real Nginx headers.
```php
// DELETE THIS IN PRODUCTION
$_SERVER['HTTP_X_AUTHENTIK_USERNAME'] = 'testuser';
$_SERVER['HTTP_X_AUTHENTIK_EMAIL'] = 'test@local';
$_SERVER['HTTP_X_AUTHENTIK_GROUPS'] = 'n-cue-cuecards,...';
```
```
