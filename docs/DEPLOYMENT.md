# Production deployment

> **What works in production today (Phase 1):** organization sign-up, users and roles, the
> super-admin console, and Asterisk health monitoring. **Phone calls do not work yet.**
> Extensions, trunks, DIDs and IVR arrive in Phases 9–11. Deploying now is useful for a staging
> environment and to get the Asterisk server ready.

## 1. What you need

| Item | Recommendation |
|---|---|
| App server | Ubuntu 24.04, 2 vCPU / 4 GB RAM (Hetzner, DigitalOcean, AWS Lightsail). For users in Sri Lanka, pick Singapore or Mumbai. |
| PBX server | A **separate** Ubuntu 24.04 VPS, 2 vCPU / 4 GB, with a static public IP |
| Private network | Same-provider VPC, or WireGuard between the two servers |
| Domains | `app.example.com` → app server, `sip.example.com` → PBX server |
| SIP trunk | A carrier account (local carrier for LK numbers, or an international provider) |
| Licensing | Check whether TRCSL (Sri Lanka) licensing applies before selling telephony services |

## 2. App server

### 2.1 Packages

```bash
sudo apt update && sudo apt upgrade -y
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y nginx postgresql redis-server supervisor git unzip certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo bash - && sudo apt install -y nodejs
```

### 2.2 Database

```bash
sudo -u postgres psql -c "CREATE USER cloudpbx WITH PASSWORD 'CHANGE_ME_LONG_RANDOM';"
sudo -u postgres psql -c "CREATE DATABASE cloudpbx OWNER cloudpbx;"
```

### 2.3 Code

```bash
sudo mkdir -p /var/www/cloudpbx && sudo chown $USER:www-data /var/www/cloudpbx
git clone <your-repo-url> /var/www/cloudpbx && cd /var/www/cloudpbx
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

Edit `.env`:

```dotenv
APP_NAME=CloudPBX
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cloudpbx
DB_USERNAME=cloudpbx
DB_PASSWORD=CHANGE_ME_LONG_RANDOM

SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1

MAIL_MAILER=smtp            # real SMTP, needed for password resets
MAIL_HOST=...
MAIL_FROM_ADDRESS=no-reply@example.com
```

Then:

```bash
php artisan migrate --force
php artisan db:seed --class=BaseDataSeeder --force   # roles, permissions, packages (safe to re-run)
php artisan platform:create-admin                     # your super admin login
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

> **Important:** never run the full `db:seed` in production. `DatabaseSeeder` skips the demo
> data when `APP_ENV=production`, but `BaseDataSeeder` is the one you want.

### 2.4 Nginx (`/etc/nginx/sites-available/cloudpbx`)

```nginx
server {
    listen 80;
    server_name app.example.com;
    root /var/www/cloudpbx/public;
    index index.php;
    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/cloudpbx /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.example.com
```

### 2.5 Queue worker and scheduler

`/etc/supervisor/conf.d/cloudpbx-worker.conf`:

```ini
[program:cloudpbx-worker]
command=php /var/www/cloudpbx/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/cloudpbx/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
# Scheduler (runs pbx:health every minute)
echo "* * * * * www-data cd /var/www/cloudpbx && php artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/cloudpbx
```

### 2.6 Firewall

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

PostgreSQL and Redis listen on localhost only by default. Keep it that way.

## 3. PBX server (Asterisk)

### 3.1 Install Asterisk 22 LTS

```bash
sudo apt install -y build-essential wget libssl-dev libncurses5-dev libnewt-dev libxml2-dev \
  libsqlite3-dev uuid-dev libjansson-dev libedit-dev libsrtp2-dev
cd /usr/src && sudo wget https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-22-current.tar.gz
sudo tar xzf asterisk-22-current.tar.gz && cd asterisk-22.*/
sudo contrib/scripts/install_prereq install
sudo ./configure --with-jansson-bundled --with-pjproject-bundled
sudo make menuselect   # ensure res_ari*, res_pjsip*, res_srtp, codec_opus (if available) are enabled
sudo make -j$(nproc) && sudo make install && sudo make samples && sudo make config
sudo systemctl enable --now asterisk
```

### 3.2 Expose ARI to the app server only

`/etc/asterisk/http.conf`:

```ini
[general]
enabled = yes
bindaddr = 10.0.0.5        ; the PBX server's PRIVATE IP, never 0.0.0.0
bindport = 8088
```

`/etc/asterisk/ari.conf`:

```ini
[general]
enabled = yes

[laravel]
type = user
read_only = no
password = LONG_RANDOM_SECRET
```

```bash
sudo asterisk -rx "core reload"
```

### 3.3 Firewall (PBX)

```bash
sudo ufw allow OpenSSH
sudo ufw allow from <APP_SERVER_PRIVATE_IP> to any port 8088 proto tcp   # ARI
sudo ufw allow from <APP_SERVER_PRIVATE_IP> to any port 5038 proto tcp   # AMI (Phase 10)
sudo ufw allow 5061/tcp                 # SIP over TLS
sudo ufw allow 8089/tcp                 # WSS for the browser web phone (Phase 10)
sudo ufw allow 10000:20000/udp          # RTP media
sudo ufw enable
sudo apt install -y fail2ban            # add an Asterisk jail against SIP scanners
```

Do **not** open 5060/UDP to the world unless your carrier requires it. If it does, allow only
the carrier's IP ranges.

### 3.4 Connect it to the platform

1. Log in to `https://app.example.com` as the platform admin.
2. Go to **PBX Servers → Add server**: driver `asterisk`, ARI URL `http://10.0.0.5:8088/ari`,
   username `laravel`, and the password from `ari.conf`.
3. Click **Check health now**. It should show **Online** with the Asterisk version.
4. Go to **Organizations** and assign the server to each organization.

## 4. Deploying updates

```bash
cd /var/www/cloudpbx
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize
sudo supervisorctl restart cloudpbx-worker:*
php artisan up
```

## 5. Backups and monitoring

- **Database:** a nightly `pg_dump cloudpbx | gzip > /backups/cloudpbx-$(date +%F).sql.gz`, copied
  off-server (S3 or similar). Test a restore at least once.
- **`.env` and `APP_KEY`:** back them up securely. Without `APP_KEY`, the encrypted PBX
  credentials cannot be decrypted.
- **Uptime:** monitor `https://app.example.com/up`.
- **Logs:** `storage/logs/laravel.log`. Set up logrotate for it.

## 6. Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS everywhere
- [ ] Real SMTP configured; password reset email tested
- [ ] Super admin created with `platform:create-admin`; no demo accounts exist
- [ ] ARI/AMI reachable only from the app server's private IP
- [ ] PBX shows **Online** on the platform console
- [ ] Nightly backups running and a restore tested
- [ ] Telecom licensing confirmed for your market
