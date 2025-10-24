#!/usr/bin/env sh
set -e

# 0) Базовая проверка: есть ли artisan и APP_KEY
if [ ! -f /var/www/html/artisan ]; then
  echo "[scheduler] artisan file is missing in /var/www/html. Check volume mount."
  exit 1
fi

if [ -z "$APP_KEY" ]; then
  echo "[scheduler] APP_KEY is empty. Did you pass .env via env_file?"
  exit 1
fi

# 1) Ждём MySQL (или поменяй на Postgres, если нужно)
echo "[scheduler] Waiting for mysql at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
until php -r '
$h=getenv("DB_HOST")?: "mysql";
$p=getenv("DB_PORT")?: "3306";
$u=getenv("DB_USERNAME")?: "root";
$pw=getenv("DB_PASSWORD")?: "";
try{ new PDO("mysql:host=$h;port=$p",$u,$pw); exit(0);}catch(Exception $e){exit(1);}';
do
  sleep 2
done
echo "[scheduler] DB is up."

# 2) Опционально — Redis (если используется в cache/queue)
if [ -n "$REDIS_HOST" ]; then
  echo "[scheduler] Waiting for redis at ${REDIS_HOST}:${REDIS_PORT:-6379}..."
  until php -r '
  $h=getenv("REDIS_HOST")?: "redis";
  $p=getenv("REDIS_PORT")?: "6379";
  $s=@fsockopen($h, (int)$p, $errno, $errstr, 1.0);
  if($s){fclose($s); exit(0);} else {exit(1);}';
  do
    sleep 2
  done
  echo "[scheduler] Redis is up."
fi

# 3) Запускаем планировщик
php -v
php artisan -V
php artisan schedule:list || true
exec php artisan schedule:work --verbose --no-interaction
