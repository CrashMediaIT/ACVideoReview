#!/bin/bash
# =========================================================
# ACVideoReview - Docker Permissions Setup Script
# =========================================================
# This script automates the directory creation and permission
# setup for Docker deployments using linuxserver/nginx container.
#
# Usage: bash deployment/setup_permissions.sh
# =========================================================

set -e  # Exit on error

echo "========================================="
echo "ACVideoReview - Docker Permissions Setup"
echo "========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if nginx container exists
if ! docker ps -a --format '{{.Names}}' | grep -q '^nginx$'; then
    echo -e "${RED}❌ Error: nginx container not found${NC}"
    echo "Please ensure the linuxserver/nginx container is running with name 'nginx'"
    echo ""
    echo "Example Docker run command:"
    echo "  docker run -d --name=nginx \\"
    echo "    -e PUID=911 -e PGID=911 -e TZ=America/Toronto \\"
    echo "    -p 80:80 -p 443:443 \\"
    echo "    -v /portainer/nginx:/config \\"
    echo "    --link mariadb:mariadb \\"
    echo "    lscr.io/linuxserver/nginx:latest"
    exit 1
fi

# Check if nginx container is running
if ! docker ps --format '{{.Names}}' | grep -q '^nginx$'; then
    echo -e "${YELLOW}⚠️  Warning: nginx container exists but is not running${NC}"
    echo "Starting nginx container..."
    docker start nginx
    sleep 3
fi

echo -e "${GREEN}✓${NC} Found nginx container"
echo ""

# Step 1: Create required directories
echo "Step 1: Creating required directories..."
echo ""

directories=(
    "/config/www/ACVideoReview/uploads"
    "/config/www/ACVideoReview/uploads/videos"
    "/config/www/ACVideoReview/uploads/thumbnails"
    "/config/www/ACVideoReview/uploads/imports"
    "/config/www/ACVideoReview/logs"
    "/config/www/ACVideoReview/tmp"
)

for dir in "${directories[@]}"; do
    echo "  Creating: $dir"
    if docker exec nginx mkdir -p "$dir" 2>/dev/null; then
        echo -e "    ${GREEN}✓${NC} Created successfully"
    elif docker exec nginx test -d "$dir" 2>/dev/null; then
        echo -e "    ${YELLOW}⚠${NC} Already exists"
    else
        echo -e "    ${RED}✗${NC} Failed to create"
        echo "    This may indicate a permission issue on the host filesystem"
    fi
done

echo -e "${GREEN}✓${NC} Directories created"
echo ""

# Step 2: Set ownership
echo "Step 2: Setting ownership to 'abc:abc' (PHP-FPM user)..."
docker exec nginx chown -R abc:abc /config/www/ACVideoReview

echo -e "${GREEN}✓${NC} Ownership set"
echo ""

# Step 3: Set permissions
echo "Step 3: Setting permissions..."
echo ""

# CRITICAL: Set root directory to 775 (allows PHP to write video_review.env during setup)
echo "  Setting root directory to 775..."
docker exec nginx chmod 775 /config/www/ACVideoReview

# Set writable directories to 775 (web server needs write access)
writable_dirs=(
    "/config/www/ACVideoReview/uploads"
    "/config/www/ACVideoReview/uploads/videos"
    "/config/www/ACVideoReview/uploads/thumbnails"
    "/config/www/ACVideoReview/uploads/imports"
    "/config/www/ACVideoReview/logs"
    "/config/www/ACVideoReview/tmp"
)

echo "  Setting writable directories to 775..."
for dir in "${writable_dirs[@]}"; do
    if docker exec nginx chmod -R 775 "$dir" 2>/dev/null; then
        : # Success, continue silently
    else
        echo -e "    ${YELLOW}⚠${NC} Warning: Could not set permissions on $dir"
    fi
done

# Set standard permissions for other directories and files
echo "  Setting standard permissions for directories (755)..."
if ! docker exec nginx find /config/www/ACVideoReview -type d -exec chmod 755 {} \; 2>/dev/null; then
    echo -e "    ${YELLOW}⚠${NC} Warning: Some directory permissions could not be set"
fi

echo "  Setting standard permissions for files (644)..."
if ! docker exec nginx find /config/www/ACVideoReview -type f -exec chmod 644 {} \; 2>/dev/null; then
    echo -e "    ${YELLOW}⚠${NC} Warning: Some file permissions could not be set"
fi

# Re-apply critical permissions (find command may have reset them)
echo "  Re-applying critical permissions..."
docker exec nginx chmod 775 /config/www/ACVideoReview

for dir in "${writable_dirs[@]}"; do
    if docker exec nginx chmod -R 775 "$dir" 2>/dev/null; then
        : # Success, continue silently
    else
        echo -e "    ${YELLOW}⚠${NC} Warning: Could not re-apply permissions on $dir"
    fi
done

echo -e "${GREEN}✓${NC} Permissions set"
echo ""

# Step 4: Verify permissions
echo "Step 4: Verifying permissions..."
echo ""

# Check root directory permissions
echo "  Root directory:"
docker exec nginx ls -ld /config/www/ACVideoReview

# Test if directory is writable by PHP
echo ""
echo "  Testing write access..."
if docker exec nginx sh -c '[ -w /config/www/ACVideoReview ]'; then
    echo -e "  ${GREEN}✅ Root directory IS writable by PHP${NC}"
else
    echo -e "  ${RED}❌ Root directory NOT writable by PHP${NC}"
    echo ""
    echo "  Troubleshooting steps:"
    echo "  1. Check ownership: docker exec nginx ls -ld /config/www/ACVideoReview"
    echo "  2. Check PHP-FPM user: docker exec nginx ps aux | grep php-fpm"
    echo "  3. Verify container user: docker exec nginx whoami"
    exit 1
fi

# Test uploads directory
if docker exec nginx sh -c '[ -w /config/www/ACVideoReview/uploads ]'; then
    echo -e "  ${GREEN}✅ Uploads directory IS writable${NC}"
else
    echo -e "  ${RED}❌ Uploads directory NOT writable${NC}"
    exit 1
fi

# Test actual write access
echo ""
echo "  Testing actual file creation..."
if docker exec nginx touch /config/www/ACVideoReview/tmp/test_write.txt 2>/dev/null && \
   docker exec nginx rm /config/www/ACVideoReview/tmp/test_write.txt 2>/dev/null; then
    echo -e "  ${GREEN}✅ Write access verified - setup can proceed${NC}"
else
    echo -e "  ${RED}❌ Write access FAILED${NC}"
    echo ""
    echo "  The container cannot write to the directory."
    echo "  Please check:"
    echo "  1. SELinux context (on Fedora/RHEL): sudo chcon -R -t container_file_t /portainer/nginx/www/ACVideoReview"
    echo "  2. File system permissions on host"
    echo "  3. Docker volume mount configuration"
    exit 1
fi

echo ""
echo "========================================="
echo -e "${GREEN}✓ Setup Complete!${NC}"
echo "========================================="
echo ""
echo "Permission Summary:"
echo "  • Root directory: 775 (allows PHP to write config file)"
echo "  • Uploads/Logs/Tmp: 775 (web server write access)"
echo "  • Regular files: 644 (readable, not writable)"
echo "  • Regular directories: 755 (traversable)"
echo "  • Owner: abc:abc (UID 911, PHP-FPM user)"
echo ""
echo "Next steps:"
echo "  1. Configure NGINX: Copy deployment/video_review.conf to /config/nginx/site-confs/"
echo "  2. Configure PHP: Copy deployment/php-config.ini to /config/php/"
echo "  3. Restart NGINX: docker restart nginx"
echo "  4. Run setup wizard: http://review.arcticwolves.ca/setup.php"
echo ""
