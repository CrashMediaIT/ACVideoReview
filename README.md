# Arctic Wolves Video Review

A comprehensive sports video review system for hockey coaches and athletes. Runs as a subdomain (`review.arcticwolves.ca`) of the [Arctic Wolves](https://github.com/CrashMediaIT/Arctic_Wolves) platform, sharing the same database and user authentication.

## Features

- **Video Management** — Upload, organize, and manage multi-camera game footage
- **Clip Editor** — Create tagged time-range segments from source videos
- **Tagging System** — Zone, skill, situation, and custom tags for clip categorization
- **Game Plans** — Pre-game, post-game, and practice strategy builder with line assignments
- **Play Diagrams** — Draw plays on a hockey rink canvas
- **Telestration** — Live drawing annotations on video during presentations
- **Device Sync** — Dual-device pairing (large screen viewer + tablet controller)
- **Calendar Integration** — Import schedules from TeamLinkt, iCal feeds, or CSV files
- **Permissions** — Granular per-user video editing access control
- **PWA Support** — Installable as a Progressive Web App on mobile devices
- **Role-Based UI** — Separate dashboard views for coaches and athletes

## Tech Stack

- **Backend**: PHP 8.0+ with PDO/MySQL
- **Frontend**: Vanilla JavaScript (no frameworks)
- **Styling**: Custom CSS design system (dark theme)
- **Database**: MySQL/MariaDB (shared with main Arctic Wolves app)
- **Web Server**: Nginx (linuxserver/nginx Docker container)

## Quick Start

### Prerequisites
- Running [Arctic Wolves](https://github.com/CrashMediaIT/Arctic_Wolves) installation with linuxserver/nginx + mariadb
- DNS record for `review.arcticwolves.ca` pointing to your server

### Setup Steps

```bash
# 1. Clone into web root
cd /portainer/nginx/www/
git clone https://github.com/CrashMediaIT/ACVideoReview.git

# 2. Set permissions
bash ACVideoReview/deployment/setup_permissions.sh

# 3. Install NGINX config
docker cp ACVideoReview/deployment/video_review.conf nginx:/config/nginx/site-confs/video_review.conf

# 4. Install PHP config
docker cp ACVideoReview/deployment/php-config.ini nginx:/config/php/php-config.ini

# 5. Restart NGINX
docker restart nginx

# 6. Run setup wizard
# Navigate to: http://review.arcticwolves.ca/setup.php
```

See **[DEPLOYMENT.md](DEPLOYMENT.md)** for the complete deployment guide with detailed instructions, troubleshooting, and production hardening.

## Project Structure

```
ACVideoReview/
├── index.php               # Entry point / login redirect
├── dashboard.php           # Main dashboard controller
├── setup.php               # First-time setup wizard
├── config/app.php          # Application constants
├── db_config.php           # Database connection
├── security.php            # Security helpers
├── database_schema.sql     # Video review schema (vr_* tables)
├── api/                    # API endpoints (13 endpoints)
├── views/                  # PHP view templates (10 views)
├── js/                     # JavaScript modules (4 files)
├── css/                    # Design system stylesheet
├── deployment/             # NGINX config, PHP config, setup scripts
├── uploads/                # User-uploaded content (git-ignored)
├── logs/                   # Application logs (git-ignored)
└── tmp/                    # Temporary files (git-ignored)
```

## License

Private repository — Arctic Wolves / Crash Media IT