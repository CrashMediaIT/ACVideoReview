# Arctic Wolves Video Review - Deployment Guide

## Table of Contents
1. [System Requirements](#system-requirements)
2. [Architecture Overview](#architecture-overview)
3. [Docker Deployment](#docker-deployment)
4. [NGINX Configuration](#nginx-configuration)
5. [PHP Configuration](#php-configuration)
6. [Database Setup](#database-setup)
7. [Setup Wizard](#setup-wizard)
8. [File Permissions](#file-permissions)
9. [Production Hardening](#production-hardening)
10. [Troubleshooting](#troubleshooting)
11. [Maintenance](#maintenance)

---

## System Requirements

### Minimum Requirements
- **PHP**: 8.0 or higher (8.1+ recommended)
- **MySQL/MariaDB**: 5.7+ / 10.3+
- **Web Server**: Nginx 1.18+
- **Disk Space**: 500MB minimum (+ storage for video files)
- **Memory**: 512MB RAM minimum

### PHP Extensions Required
```bash
php-mysql (or php-mysqli)
php-pdo
php-mbstring
php-json
php-session
php-fileinfo
```

### Optional for Enhanced Features
```bash
php-gd        # Thumbnail generation
php-curl       # Calendar import (iCal, TeamLinkt)
php-ffmpeg     # Video processing / transcoding
```

---

## Architecture Overview

The Video Review system runs as a **subdomain** of the main Arctic Wolves application:

```
Main App:     https://arcticwolves.ca      → /config/www/Arctic_Wolves
Video Review: https://review.arcticwolves.ca → /config/www/ACVideoReview
```

Both applications share the **same database** (`arctic_wolves`). The Video Review tables are prefixed with `vr_` to avoid conflicts with the main application tables.

Authentication flows through the main Arctic Wolves login system. Users are redirected to `arcticwolves.ca/login.php` if not authenticated.

### Directory Structure
```
ACVideoReview/
├── index.php               # Entry point / login redirect
├── dashboard.php           # Main dashboard controller
├── setup.php               # First-time setup wizard
├── db_config.php           # Database connection
├── security.php            # Security helpers (CSRF, headers, auth)
├── database_schema.sql     # Video review tables (vr_* prefix)
├── manifest.json           # PWA manifest
├── config/
│   └── app.php             # Application constants
├── api/                    # AJAX/form API endpoints
│   ├── calendar_import.php
│   ├── clip_delete.php
│   ├── clip_save.php
│   ├── device_sync.php
│   ├── draw_play_save.php
│   ├── game_plan_delete.php
│   ├── game_plan_save.php
│   ├── game_plan_status.php
│   ├── notification_read.php
│   ├── permission_toggle.php
│   ├── telestration_save.php
│   ├── video_delete.php
│   └── video_upload.php
├── css/
│   └── style-guide.css     # Complete design system
├── js/
│   ├── app.js              # Core app functionality
│   ├── video-player.js     # Video player controls
│   ├── telestration.js     # Drawing canvas engine
│   └── device-sync.js      # Device pairing sync
├── views/                  # PHP view templates
│   ├── home_coach.php
│   ├── home_athlete.php
│   ├── video_review.php
│   ├── my_clips.php
│   ├── calendar.php
│   ├── game_plan.php
│   ├── film_room.php
│   ├── permissions.php
│   ├── telestrate.php
│   └── pair_device.php
├── deployment/             # Deployment configuration files
│   ├── default.conf        # NGINX main site config (replaces stock default)
│   ├── video_review.conf   # NGINX subdomain config (review.arcticwolves.ca)
│   ├── php-config.ini      # PHP-FPM configuration
│   └── setup_permissions.sh # Docker permissions script
├── uploads/                # User-uploaded content
│   ├── videos/
│   ├── thumbnails/
│   └── imports/
├── logs/                   # Application logs
└── tmp/                    # Temporary files
```

---

## Docker Deployment

This application is designed to run alongside the main Arctic Wolves application in a **linuxserver/nginx** Docker container.

### Prerequisites
- **linuxserver/nginx** container running (same container as main app)
- **linuxserver/mariadb** container running with the `arctic_wolves` database
- DNS record: `review.arcticwolves.ca` → your server IP

### Step 1: Clone Repository

```bash
# Clone into the nginx web root
cd /portainer/nginx/www/   # or your nginx volume mount path
git clone https://github.com/CrashMediaIT/ACVideoReview.git
```

### Step 2: Run Permissions Setup

```bash
# Automated setup (recommended)
bash /portainer/nginx/www/ACVideoReview/deployment/setup_permissions.sh
```

Or manually:

```bash
# Create required directories
docker exec nginx mkdir -p /config/www/ACVideoReview/uploads/videos
docker exec nginx mkdir -p /config/www/ACVideoReview/uploads/thumbnails
docker exec nginx mkdir -p /config/www/ACVideoReview/uploads/imports
docker exec nginx mkdir -p /config/www/ACVideoReview/logs
docker exec nginx mkdir -p /config/www/ACVideoReview/tmp

# Set ownership to PHP-FPM user
docker exec nginx chown -R abc:abc /config/www/ACVideoReview

# Set root directory writable for setup
docker exec nginx chmod 775 /config/www/ACVideoReview

# Set writable directories
docker exec nginx chmod -R 775 /config/www/ACVideoReview/uploads
docker exec nginx chmod -R 775 /config/www/ACVideoReview/logs
docker exec nginx chmod -R 775 /config/www/ACVideoReview/tmp
```

### Step 3: Configure NGINX

The linuxserver/nginx default configuration uses `server_name _;` which catches **all** traffic, including subdomain requests meant for this application. You must replace it with the provided `default.conf` that uses an explicit `server_name` so that `review.arcticwolves.ca` is routed to the Video Review app instead of the main dashboard.

```bash
# Replace the default site config so it doesn't capture subdomain traffic
docker cp /portainer/nginx/www/ACVideoReview/deployment/default.conf \
    nginx:/config/nginx/site-confs/default.conf

# Copy video review subdomain config
docker cp /portainer/nginx/www/ACVideoReview/deployment/video_review.conf \
    nginx:/config/nginx/site-confs/video_review.conf

# Verify configuration syntax
docker exec nginx nginx -t

# Restart nginx to apply
docker restart nginx
```

> **Why both files?** The default `server_name _;` in the stock linuxserver/nginx config acts as a catch-all that intercepts requests to `review.arcticwolves.ca` before the subdomain server block can handle them. The provided `default.conf` restricts the main site to `arcticwolves.ca` and `www.arcticwolves.ca` only, allowing the video review subdomain to work correctly.

### Step 4: Configure PHP

```bash
# Copy PHP config
docker cp /portainer/nginx/www/ACVideoReview/deployment/php-config.ini \
    nginx:/config/php/php-config.ini

# Restart nginx to apply PHP-FPM changes
docker restart nginx
```

### Step 5: Import Database Schema

```bash
# Import the video review tables into the existing arctic_wolves database
docker exec -i mariadb mysql -u<DB_USER> -p<DB_PASS> arctic_wolves \
    < /portainer/nginx/www/ACVideoReview/database_schema.sql
```

### Step 6: Run Setup Wizard

1. Navigate to `http://review.arcticwolves.ca/setup.php`
2. **Step 1 – Database**: Enter your MariaDB credentials (host: `mariadb`, database: `arctic_wolves`)
3. **Step 2 – URLs**: Confirm the Video Review URL and main app URL
4. **Step 3 – Finalize**: Click to complete setup

### Step 7: Post-Setup Security

After setup is complete, restrict access to `setup.php`:

```bash
# Option 1: Uncomment the deny block in video_review.conf
# Edit /config/nginx/site-confs/video_review.conf and uncomment:
#   location = /setup.php { deny all; return 404; }

# Option 2: Delete setup.php
docker exec nginx rm /config/www/ACVideoReview/setup.php

# Restart nginx
docker restart nginx
```

---

## NGINX Configuration

Two NGINX configuration files are provided in `deployment/`:

| File | Purpose |
|------|---------|
| `default.conf` | Main site (`arcticwolves.ca`) — replaces the stock linuxserver/nginx default to prevent it from catching subdomain traffic |
| `video_review.conf` | Video Review subdomain (`review.arcticwolves.ca`) |

### Key Features (video_review.conf)
- **Large file uploads**: 2GB max (`client_max_body_size 2G`)
- **Extended timeouts**: 10 minutes for video upload/processing
- **Security headers**: CSP, X-Frame-Options, XSS protection (matching `security.php`)
- **Sensitive file protection**: Blocks access to `.env`, `.sql`, `db_config.php`, `security.php`
- **Video streaming**: Range requests enabled for seek support
- **Static asset caching**: 1 year for CSS/JS/fonts, 30 days for videos
- **Gzip compression**: Enabled for text-based assets
- **Documentation access**: `.md` files in `deployment/` are accessible

### Installing the Configuration

```bash
# Replace default site config (required for subdomain routing)
docker cp deployment/default.conf nginx:/config/nginx/site-confs/default.conf

# Copy video review subdomain config
docker cp deployment/video_review.conf nginx:/config/nginx/site-confs/video_review.conf

# Verify configuration syntax
docker exec nginx nginx -t

# Reload nginx (graceful)
docker exec nginx nginx -s reload
```

### SSL/HTTPS Setup

The configuration includes commented SSL blocks. To enable HTTPS:

1. Obtain SSL certificate (e.g., via Let's Encrypt / Certbot)
2. Uncomment the SSL server block in `video_review.conf`
3. Uncomment the HTTP→HTTPS redirect block
4. Comment out or remove the HTTP server block
5. Reload nginx

---

## PHP Configuration

The PHP configuration file is located at `deployment/php-config.ini`.

### Key Settings
| Setting | Value | Purpose |
|---------|-------|---------|
| `upload_max_filesize` | 2048M | Allow 2GB video uploads |
| `post_max_size` | 2048M | Match upload limit |
| `memory_limit` | 512M | Video processing headroom |
| `max_execution_time` | 600 | 10 min for large uploads |
| `session.cookie_httponly` | 1 | Prevent XSS session theft |
| `session.cookie_secure` | 1 | HTTPS-only cookies |
| `expose_php` | Off | Hide PHP version |

### Installing
```bash
docker cp deployment/php-config.ini nginx:/config/php/php-config.ini
docker restart nginx
```

---

## Database Setup

The Video Review system extends the existing Arctic Wolves database with 15 tables, all prefixed with `vr_`:

| Table | Purpose |
|-------|---------|
| `vr_video_sources` | Multi-camera video files (upload, recording, stream, NDI) |
| `vr_video_clips` | Tagged time-range segments |
| `vr_tags` | Tag definitions (zone, skill, situation, custom) |
| `vr_clip_tags` | Clip ↔ tag associations |
| `vr_clip_athletes` | Athletes tagged in clips |
| `vr_game_plans` | Pre-game, post-game, practice plans |
| `vr_line_assignments` | Line/roster assignments |
| `vr_draw_plays` | Play diagrams (canvas JSON) |
| `vr_review_sessions` | Scheduled review sessions |
| `vr_review_session_clips` | Clips in review sessions |
| `vr_calendar_imports` | External calendar sources |
| `vr_video_permissions` | Per-user video permissions |
| `vr_notifications` | Video review notifications |
| `vr_device_sessions` | Viewer/controller pairing |
| `vr_telestrations` | Canvas annotations on video |

### Manual Import
```bash
# From host
docker exec -i mariadb mysql -u<user> -p<pass> arctic_wolves < database_schema.sql

# Or inside container
docker exec -it mariadb bash
mysql -u<user> -p<pass> arctic_wolves < /path/to/database_schema.sql
```

### Verify Tables
```bash
docker exec mariadb mysql -u<user> -p<pass> arctic_wolves -e "SHOW TABLES LIKE 'vr_%';"
```

You should see all 15 `vr_` tables listed.

### NDI Camera Integration

The Video Review system can record from NDI (Network Device Interface) cameras managed in the main Arctic Wolves platform. NDI cameras are configured via **System Tools → NDI Cameras** in the main Arctic Wolves admin dashboard and stored in the shared `ndi_cameras` table.

To use NDI cameras for recording:
1. Configure NDI cameras in the main Arctic Wolves application under System Tools
2. Ensure the NDI camera devices are powered on and connected to the network
3. In the Film Room → Upload tab, click **"Record from NDI Camera"**
4. Select the desired camera from the list of active NDI sources
5. Use screen/display capture to select the NDI Tools Monitor or Studio Monitor window
6. Record the session, then save it with metadata (title, camera angle, game, team)

NDI recordings are stored with `source_type='ndi'` and linked to the originating camera via `ndi_camera_id`.

---

## Setup Wizard

The setup wizard (`setup.php`) automates first-time configuration:

### What It Does
1. **Automatic permission setup**: Creates required directories and sets permissions
2. **Database connection**: Tests and saves database credentials to `video_review.env`
3. **Schema import**: Imports `database_schema.sql` into the database
4. **URL configuration**: Sets application and main app URLs
5. **Finalization**: Verifies everything works and creates `.setup_complete` flag

### Re-running Setup
If you need to re-run setup:
```bash
# Remove the setup complete flag
docker exec nginx rm /config/www/ACVideoReview/.setup_complete
docker exec nginx rm /config/www/ACVideoReview/.permissions_setup_done

# Access setup.php again
# Or force: http://review.arcticwolves.ca/setup.php?force=1
```

---

## File Permissions

### Permission Summary
| Path | Permission | Purpose |
|------|-----------|---------|
| Root directory | 775 | PHP writes `.env` during setup |
| `uploads/` | 775 | Video/image uploads |
| `logs/` | 775 | Application logging |
| `tmp/` | 775 | Temporary file storage |
| PHP files | 644 | Read-only execution |
| Other directories | 755 | Standard traversal |

### Docker User
The linuxserver/nginx container runs PHP-FPM as the `abc` user (UID 911, GID 911).

### Verify Permissions
```bash
# Check ownership
docker exec nginx ls -la /config/www/ACVideoReview/

# Test write access
docker exec nginx sh -c '[ -w /config/www/ACVideoReview/uploads ] && echo "✅ Writable" || echo "❌ Not writable"'
```

---

## Production Hardening

### 1. Restrict Setup File
```bash
# Uncomment in video_review.conf:
location = /setup.php {
    deny all;
    return 404;
}
```

### 2. Enable HTTPS
Uncomment the SSL server block in `video_review.conf` and configure certificates.

### 3. Secure Environment File
```bash
docker exec nginx chmod 640 /config/www/ACVideoReview/video_review.env
```

### 4. Enable Error Logging (Disable Display)
Already configured in `deployment/php-config.ini`:
```ini
display_errors = Off
log_errors = On
```

### 5. Regular Backups
```bash
# Database backup
docker exec mariadb mysqldump -u<user> -p<pass> arctic_wolves \
    --tables vr_video_sources vr_video_clips vr_tags vr_clip_tags \
    vr_clip_athletes vr_game_plans vr_line_assignments vr_draw_plays \
    vr_review_sessions vr_review_session_clips vr_calendar_imports \
    vr_video_permissions vr_notifications vr_device_sessions vr_telestrations \
    | gzip > vr_backup_$(date +%Y%m%d_%H%M%S).sql.gz

# File backup (uploaded videos)
tar -czf vr_uploads_$(date +%Y%m%d).tar.gz /portainer/nginx/www/ACVideoReview/uploads/
```

---

## Troubleshooting

### Subdomain Goes to Main Dashboard Instead of Video Review
The most common cause is the stock linuxserver/nginx `default.conf` catching all traffic (including subdomains) with `server_name _;`.
```bash
# Check which server block handles review.arcticwolves.ca
docker exec nginx nginx -T 2>/dev/null | grep -A2 'server_name'

# Fix: replace default.conf with the one from this repo
docker cp /portainer/nginx/www/ACVideoReview/deployment/default.conf \
    nginx:/config/nginx/site-confs/default.conf
docker cp /portainer/nginx/www/ACVideoReview/deployment/video_review.conf \
    nginx:/config/nginx/site-confs/video_review.conf
docker exec nginx nginx -t && docker restart nginx
```

### NGINX Returns 403 Forbidden
```bash
# Check file ownership
docker exec nginx ls -la /config/www/ACVideoReview/

# Fix ownership
docker exec nginx chown -R abc:abc /config/www/ACVideoReview

# Check nginx config syntax
docker exec nginx nginx -t
```

### Video Upload Fails
```bash
# Check PHP upload limits
docker exec nginx php -i | grep upload_max

# Check nginx body size limit (should be 2G)
docker exec nginx nginx -T | grep client_max_body_size

# Check available disk space
docker exec nginx df -h /config/www/ACVideoReview/uploads/
```

### Database Connection Fails
```bash
# Test from nginx container
docker exec nginx php -r "
try {
    new PDO('mysql:host=mariadb;dbname=arctic_wolves', '<user>', '<pass>');
    echo 'Connected successfully\n';
} catch(PDOException \$e) {
    echo 'Failed: ' . \$e->getMessage() . '\n';
}"

# Check environment file
docker exec nginx cat /config/www/ACVideoReview/video_review.env
```

### Session/Auth Issues
```bash
# Check PHP session directory
docker exec nginx php -i | grep session.save_path

# Verify sessions work
docker exec nginx php -r "session_start(); echo session_id() . '\n';"
```

### View Logs
```bash
# NGINX access log
docker exec nginx tail -f /config/log/video_review_access.log

# NGINX error log
docker exec nginx tail -f /config/log/video_review_error.log

# PHP error log
docker exec nginx tail -f /config/log/php-error.log

# Application logs
docker exec nginx tail -f /config/www/ACVideoReview/logs/*.log
```

---

## Maintenance

### Update Application
```bash
# Pull latest changes
cd /portainer/nginx/www/ACVideoReview
git pull

# Re-apply permissions
bash deployment/setup_permissions.sh

# Restart nginx
docker restart nginx
```

### Database Migrations
If `database_schema.sql` has been updated:
```bash
# The schema uses CREATE TABLE IF NOT EXISTS, safe to re-run
docker exec -i mariadb mysql -u<user> -p<pass> arctic_wolves < database_schema.sql
```

### Clear Temporary Files
```bash
docker exec nginx find /config/www/ACVideoReview/tmp -type f -mtime +7 -delete
```

### Monitor Disk Usage
```bash
# Check video storage usage
docker exec nginx du -sh /config/www/ACVideoReview/uploads/videos/
docker exec nginx du -sh /config/www/ACVideoReview/uploads/thumbnails/
```

---

## Quick Reference

### Service Commands
```bash
# Restart nginx
docker restart nginx

# Check nginx config
docker exec nginx nginx -t

# Reload nginx (graceful)
docker exec nginx nginx -s reload

# View running containers
docker ps
```

### Key File Locations
| File | Location |
|------|----------|
| NGINX default config | `/config/nginx/site-confs/default.conf` |
| NGINX subdomain config | `/config/nginx/site-confs/video_review.conf` |
| PHP config | `/config/php/php-config.ini` |
| Environment file | `/config/www/ACVideoReview/video_review.env` |
| NGINX access log | `/config/log/video_review_access.log` |
| NGINX error log | `/config/log/video_review_error.log` |
| PHP error log | `/config/log/php-error.log` |
| Application root | `/config/www/ACVideoReview` |
| Video uploads | `/config/www/ACVideoReview/uploads/videos/` |

---

**Last Updated**: February 2026
**Version**: 1.0.0
