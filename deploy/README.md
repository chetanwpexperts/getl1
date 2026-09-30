# Deploying GetL1 to the Hostinger VPS

What you get:

| URL | What | Where on the server |
| --- | --- | --- |
| https://getl1.com | Coming-soon page + waitlist | `/var/www/getl1-site/landing/public` |
| https://staging.getl1.com | Laravel app (password-protected, not indexed) | `/var/www/getl1-staging` |
| (not public) | Waitlist signups CSV | `/var/www/getl1-data/waitlist.csv` |

The VPS is shared. The setup script only **adds** things: two new nginx sites, one new database,
new folders, one supervisor worker and one cron file. It never edits other nginx sites, databases,
the firewall or the system Node version.

## 1. Point the domain at the VPS (5 min, then wait for DNS)

In your domain's DNS panel (Hostinger → Domains → getl1.com → DNS / Nameservers), add:

| Type | Name | Value | TTL |
| --- | --- | --- | --- |
| A | `@` | your VPS IP | 300 |
| A | `www` | your VPS IP | 300 |
| A | `staging` | your VPS IP | 300 |

Delete any existing A/AAAA/CNAME for `@` or `www` that point to a Hostinger parking page.
Check it has propagated: `ping getl1.com` should show your VPS IP (usually 5–30 min).

## 2. Give the VPS read access to the private repo (one time)

On the VPS, as **your own user** (not root):

```bash
ssh-keygen -t ed25519 -C "getl1-vps" -f ~/.ssh/getl1_deploy -N ""
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/getl1_deploy
  IdentitiesOnly yes
EOF
cat ~/.ssh/getl1_deploy.pub
```

If you already have a `Host github.com` block in `~/.ssh/config` for OutraqHQ, don't add a second
one. Instead add the key to your GitHub account (Settings → SSH keys) so one key works for both repos.

Otherwise copy the printed key → GitHub → `chetanwpexperts/getl1` → Settings → **Deploy keys** →
Add key (read-only is fine). Test: `ssh -T git@github.com`.

## 3. Clone and run the setup (about 5–10 min)

GetL1 creates three folders side by side: `getl1-staging`, `getl1-site` and `getl1-data`. By default
they go in `/var/www`. To keep them in another folder you already use (e.g. `cdata`), set
`GETL1_BASE` to that folder's full path. Other projects in that folder are not touched.

```bash
BASE=/var/www                      # or the full path of your folder, e.g. /var/www/cdata
git clone git@github.com:chetanwpexperts/getl1.git $BASE/getl1-staging
cd $BASE/getl1-staging
sudo GETL1_BASE=$BASE CERT_EMAIL=you@example.com bash deploy/setup-vps.sh
```

It asks for a **staging username and password**. That's the browser login you and your testers use
to open staging.getl1.com. The script is safe to re-run if anything stops halfway.

If DNS isn't pointing yet, it skips SSL and tells you. Re-run the same command once DNS is live.

## 4. Everyday use

```bash
# after pushing to main, update staging
bash $BASE/getl1-staging/deploy/deploy-staging.sh

# after changing landing/public, update getl1.com
bash $BASE/getl1-staging/deploy/deploy-landing.sh

# see who joined the waitlist
sudo cat $BASE/getl1-data/waitlist.csv

# add another staging tester
sudo htpasswd -B /etc/nginx/.htpasswd-getl1-staging tester-name

# logs
tail -f $BASE/getl1-staging/storage/logs/laravel-*.log
sudo supervisorctl status getl1-staging-worker
```

Demo logins on staging (password `password`): `buyer@getl1.test`, `supplier1@getl1.test` … `supplier5@getl1.test`.

## If something fails

- **"Apache is serving port 80"**: this server uses Apache, not nginx. Stop and ask for Apache configs.
- **Database step fails**: MySQL root has a password. Run `sudo mysql -uroot -p`, then:

  ```sql
  CREATE DATABASE getl1_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'getl1_staging'@'localhost' IDENTIFIED BY '<the password in /root/.getl1-staging-db-pass>';
  GRANT ALL PRIVILEGES ON getl1_staging.* TO 'getl1_staging'@'localhost';
  ```

  and re-run the script.
- **nginx test fails**: the script disables only the two GetL1 sites again, so everything else keeps running.
  Send the printed error.
- **Firewall**: if `sudo ufw status` is active and doesn't allow 80/443, run `sudo ufw allow 'Nginx Full'`
  (check with Virendar first, since the server is shared).
