-- Increase MySQL buffer sizes to prevent "Out of sort memory" errors
-- These settings are applied automatically on MySQL container startup

SET GLOBAL sort_buffer_size = 16777216;  -- 16 MB (default: 256 KB)
SET GLOBAL read_rnd_buffer_size = 8388608;  -- 8 MB (default: 256 KB)

-- Verify settings
SELECT
    @@GLOBAL.sort_buffer_size / 1024 / 1024 AS sort_buffer_MB,
    @@GLOBAL.read_rnd_buffer_size / 1024 / 1024 AS read_rnd_buffer_MB;
