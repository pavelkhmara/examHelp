#!/bin/bash
# MySQL startup script to apply buffer settings

set -e

echo "Waiting for MySQL to be ready..."
until mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; do
  sleep 1
done

echo "Applying MySQL buffer settings..."
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<EOF
SET GLOBAL sort_buffer_size = 16777216;
SET GLOBAL read_rnd_buffer_size = 8388608;
SELECT 'MySQL buffers configured:' as status,
       @@GLOBAL.sort_buffer_size / 1024 / 1024 AS sort_buffer_MB,
       @@GLOBAL.read_rnd_buffer_size / 1024 / 1024 AS read_rnd_buffer_MB;
EOF

echo "MySQL buffer settings applied successfully!"
