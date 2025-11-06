#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# context-pack.sh - Enhanced Laravel Repository Context Generator
# ============================================================================
# Generates comprehensive documentation of your Laravel application including:
# - Project metadata and dependencies
# - Architecture overview
# - Database schema and models with relationships
# - All application layers (Controllers, Services, Jobs, etc.)
# - Nova admin resources
# - Tests and configuration
# ============================================================================

# Debug: set DEBUG=1 to print internals
DEBUG="${DEBUG:-0}"
dbg() { if [[ "$DEBUG" == "1" ]]; then echo "[context-pack][debug]" "$@" >&2; fi }

# =========================
# Config / detection
# =========================
MODE="${1:-all}"               # all | models | db | models+db | nova | file | metadata
TARGET_FILE="${2:-}"           # used when MODE=file
OUT="${OUT:-repo-context.md}"  # can be overridden: OUT=... ./context-pack.sh ...
IGNORE_DIRS="node_modules|\.git|build|dist|\.next|coverage|vendor|storage|tmp|uploads|bootstrap/cache"

# гарантируем, что каталог для OUT существует
ensure_out_dir() {
  local dir
  dir="$(dirname "$OUT")"
  if [[ "$dir" != "." && ! -d "$dir" ]]; then
    mkdir -p "$dir"
  fi
}

# Prefer container if available; otherwise fallback to host
ARTISAN_CMD="${ARTISAN_CMD:-}"
if [[ -z "${ARTISAN_CMD}" ]]; then
  if command -v docker >/dev/null 2>&1 && docker compose ps >/dev/null 2>&1; then
    ARTISAN_CMD="docker compose exec -T app php artisan"
  else
    ARTISAN_CMD="php artisan"
  fi
fi
dbg "ARTISAN_CMD=$ARTISAN_CMD"

heading() { echo -e "\n## $1\n" >> "$OUT"; }
subheading() { echo -e "\n### $1\n" >> "$OUT"; }
subsubheading() { echo -e "\n#### $1\n" >> "$OUT"; }
codef() { local lang="$1"; echo -e "\n\`\`\`$lang" >> "$OUT"; cat; echo -e "\`\`\`" >> "$OUT"; }

# Run a tiny PHP that boots Laravel and executes given PHP code.
# Usage:
#   laravel_php "<php code without <?php ?>" [ENV1=val1] [ENV2=val2] ...
# Captures ONLY STDOUT of PHP script. Any exception text printed on STDOUT will be returned too,
# so call-sites should prefix important lines to parse safely.
laravel_php() {
  local php_code="$1"; shift || true
  local cleaned; cleaned="$(printf "%s" "$php_code" | sed -E 's/<\?php//g; s/\?>//g')"

  mkdir -p storage/framework/cache 2>/dev/null || true
  local tmp_rel="storage/framework/cache/lrv-$(date +%s)-$RANDOM.php"
  local tmp_abs="$tmp_rel"

  cat > "$tmp_abs" <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
// --- USER CODE BELOW ---
PHP
  printf "%s\n" "$cleaned" >> "$tmp_abs"

  # collect env pairs
  local -a env_args=()
  while (($#)); do env_args+=("$1"); shift; done

  if [[ "$ARTISAN_CMD" == *"docker compose exec"* ]]; then
    local -a docker_env=()
    for kv in "${env_args[@]}"; do docker_env+=(-e "$kv"); done
    if ! docker compose exec -T "${docker_env[@]}" app php "$tmp_rel" </dev/null; then
      if [[ "$DEBUG" == "1" ]]; then
        echo "[context-pack][debug] PHP temp file (container path): $tmp_rel" >&2
        echo "[context-pack][debug] --- BEGIN temp file ---" >&2
        cat "$tmp_abs" >&2 || true
        echo "[context-pack][debug] --- END temp file ---" >&2
      fi
      rm -f "$tmp_abs" || true
      return 1
    fi
  else
    if ! env "${env_args[@]}" php "$tmp_abs" </dev/null; then
      if [[ "$DEBUG" == "1" ]]; then
        echo "[context-pack][debug] PHP temp file: $tmp_abs" >&2
        echo "[context-pack][debug] --- BEGIN temp file ---" >&2
        cat "$tmp_abs" >&2 || true
        echo "[context-pack][debug] --- END temp file ---" >&2
      fi
      rm -f "$tmp_abs" || true
      return 1
    fi
  fi
  rm -f "$tmp_abs" || true
}


# =========================
# Sections
# =========================

emit_repo_header() {
  ensure_out_dir
  cat > "$OUT" << 'HEADER'
# 📚 Complete Repository Context

> **Auto-generated comprehensive documentation of the Laravel application**

---

HEADER
  echo "**Generated:** $(date '+%Y-%m-%d %H:%M:%S')" >> "$OUT"
  echo "" >> "$OUT"
}

emit_table_of_contents() {
  heading "📋 Table of Contents"
  cat >> "$OUT" << 'TOC'
1. [Project Metadata](#-project-metadata)
2. [Architecture Overview](#️-architecture-overview)
3. [File Structure](#-complete-file-structure)
4. [Core Configuration](#️-core-configuration-files)
5. [Database Schema](#️-database--current-schema-live)
6. [Models & Relationships](#️-models--relationships)
7. [Routes](#️-routes)
8. [Controllers](#-controllers)
9. [Services](#-services)
10. [Middleware](#️-middleware)
11. [Policies](#-policies)
12. [Jobs & Queues](#-jobs--queues)
13. [Events & Listeners](#-events--listeners)
14. [Console Commands](#️-console-commands)
15. [API Resources](#-api-resources)
16. [Migrations](#-migrations)
17. [Service Providers](#️-service-providers)
18. [Nova Resources](#-nova-resources)
19. [Frontend & Views](#-frontend--views)
20. [Tests](#-tests)
21. [Project Summary](#-project-summary)

---
TOC
}

emit_project_metadata() {
  heading "📦 Project Metadata"

  subheading "🔍 Environment Information"
  {
    echo "- **Working Directory:** \`$(pwd)\`"
    echo "- **Git Branch:** \`$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "N/A")\`"
    echo "- **Last Commit:** \`$(git log -1 --format='%h - %s (%ar)' 2>/dev/null || echo "N/A")\`"
    echo "- **PHP Version:** \`$(php -v | head -n 1 || echo "N/A")\`"
  } >> "$OUT"

  subheading "📦 Laravel & Key Dependencies"
  if [[ -f composer.json ]]; then
    {
      echo "**Main Dependencies:**"
      echo ""
      echo "| Package | Version |"
      echo "|---------|---------|"
      cat composer.json | grep -A 30 '"require"' | grep -E '^\s+"' | grep -v '"require"' | head -15 | \
        sed 's/[",]//g' | sed 's/^[[:space:]]*//' | awk '{printf "| %s | %s |\n", $1, $2}'
      echo ""
      echo "**Development Dependencies:**"
      echo ""
      echo "| Package | Version |"
      echo "|---------|---------|"
      cat composer.json | grep -A 20 '"require-dev"' | grep -E '^\s+"' | grep -v '"require-dev"' | head -10 | \
        sed 's/[",]//g' | sed 's/^[[:space:]]*//' | awk '{printf "| %s | %s |\n", $1, $2}'
    } >> "$OUT"
  fi

  subheading "🎨 Frontend Dependencies"
  if [[ -f package.json ]]; then
    {
      echo "| Package | Version |"
      echo "|---------|---------|"
      cat package.json | grep -A 20 '"dependencies"' | grep -E '^\s+"' | grep -v '"dependencies"' | head -10 | \
        sed 's/[",]//g' | sed 's/^[[:space:]]*//' | awk '{printf "| %s | %s |\n", $1, $2}'
    } >> "$OUT"
  fi

  subheading "🔧 Artisan Commands Available"
  if command -v $ARTISAN_CMD >/dev/null 2>&1; then
    {
      $ARTISAN_CMD list --format=txt 2>/dev/null | head -50 || echo "Unable to list commands"
    } | codef txt >> "$OUT"
  fi
}

emit_architecture_overview() {
  heading "🏗️ Architecture Overview"

  subheading "📊 Component Statistics"
  {
    echo "| Component | Count |"
    echo "|-----------|-------|"
    echo "| Controllers | $(find app/Http/Controllers -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Models | $(find app/Models -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Services | $(find app/Services -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Middleware | $(find app/Http/Middleware -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Policies | $(find app/Policies -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Jobs | $(find app/Jobs -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Events | $(find app/Events -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Listeners | $(find app/Listeners -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Commands | $(find app/Console/Commands -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Migrations | $(find database/migrations -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Seeders | $(find database/seeders -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Factories | $(find database/factories -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Nova Resources | $(find app/Nova -name "*.php" 2>/dev/null | wc -l) |"
    echo "| Tests | $(find tests -name "*Test.php" 2>/dev/null | wc -l) |"
    echo "| Views | $(find resources/views -name "*.blade.php" 2>/dev/null | wc -l) |"
  } >> "$OUT"

  subheading "🎯 Technology Stack Detected"
  {
    echo "- **Framework:** Laravel $(grep -o '"laravel/framework": "[^"]*"' composer.json | cut -d'"' -f4 || echo "N/A")"
    [[ -f "composer.json" ]] && grep -q "laravel/nova" composer.json && echo "- **Admin Panel:** Laravel Nova"
    [[ -f "composer.json" ]] && grep -q "laravel/sanctum" composer.json && echo "- **Authentication:** Laravel Sanctum"
    [[ -f "composer.json" ]] && grep -q "spatie/laravel-permission" composer.json && echo "- **Authorization:** Spatie Laravel Permission"
    [[ -f "vite.config.js" ]] && echo "- **Build Tool:** Vite"
    [[ -f "tailwind.config.js" ]] && echo "- **CSS Framework:** Tailwind CSS"
    [[ -f "docker-compose.yml" ]] && echo "- **Containerization:** Docker Compose"
    [[ -d ".github/workflows" ]] && echo "- **CI/CD:** GitHub Actions"
    [[ -f "phpunit.xml" ]] && echo "- **Testing:** PHPUnit"
  } >> "$OUT"
}

emit_file_tree() {
  heading "📁 Complete File Structure"
  if command -v tree >/dev/null 2>&1; then
    tree -a -I "$IGNORE_DIRS" -L 4 --dirsfirst 2>/dev/null | codef txt >> "$OUT"
  else
    { find . -type f | grep -Ev "$IGNORE_DIRS" | sed 's|^\./||' | head -200 | sort; } | codef txt >> "$OUT"
  fi
}

emit_core_configs() {
  heading "⚙️ CORE CONFIGURATION FILES"

  subheading "🔧 Application Configs"
  local critical_configs=("composer.json" "package.json" "vite.config.js" "tailwind.config.js" "phpunit.xml" ".env.example" "docker-compose.yml" "Dockerfile")
  for config in "${critical_configs[@]}"; do
    if [[ -f "$config" ]]; then
      echo -e "\n#### \`$config\`" >> "$OUT"
      cat "$config" | codef "${config##*.}" >> "$OUT"
    fi
  done

  subheading "⚙️ Laravel Config Files"
  if [[ -d config ]]; then
    find config -name "*.php" -type f | sort | head -15 | while read -r f; do
      [[ -f "$f" ]] || continue
      echo -e "\n#### \`$f\`" >> "$OUT"
      {
        echo "// Key configuration settings"
        head -n 50 "$f"
      } | codef php >> "$OUT"
    done
  fi
}

emit_routes() {
  heading "🛣️ Routes"

  subheading "🌐 Web Routes"
  if [[ -f routes/web.php ]]; then
    cat routes/web.php | codef php >> "$OUT"
  fi

  subheading "🔌 API Routes"
  if [[ -f routes/api.php ]]; then
    cat routes/api.php | codef php >> "$OUT"
  fi

  subheading "📝 Console Routes"
  if [[ -f routes/console.php ]]; then
    cat routes/console.php | codef php >> "$OUT"
  fi

  subheading "📡 All Registered Routes"
  if command -v $ARTISAN_CMD >/dev/null 2>&1; then
    {
      $ARTISAN_CMD route:list --columns=method,uri,name,action 2>/dev/null | head -100 || echo "Unable to list routes"
    } | codef txt >> "$OUT"
  fi
}

emit_controllers() {
  heading "🎮 Controllers"

  if [[ ! -d app/Http/Controllers ]]; then
    echo "_No controllers directory found._" >> "$OUT"
    return
  fi

  find app/Http/Controllers -name "*.php" -type f | sort | head -15 | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      echo "// Controller methods and structure"
      grep -E 'class\s+[A-Za-z_][A-Za-z0-9_]*|public\s+function\s+[A-Za-z_]' -n "$f" | head -20 || true
      echo ""
      echo "// Full content (first 80 lines)"
      head -n 80 "$f"
    } | codef php >> "$OUT"
  done
}

emit_services() {
  heading "🔧 Services"

  if [[ ! -d app/Services ]]; then
    echo "_No services directory found._" >> "$OUT"
    return
  fi

  find app/Services -name "*.php" -type f | sort | head -10 | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      echo "// Service methods"
      grep -E 'class\s+[A-Za-z_][A-Za-z0-9_]*|public\s+function\s+[A-Za-z_]' -n "$f" | head -30 || true
      echo ""
      echo "// Full content (first 100 lines)"
      head -n 100 "$f"
    } | codef php >> "$OUT"
  done
}

emit_middleware() {
  heading "🛡️ Middleware"

  if [[ ! -d app/Http/Middleware ]]; then
    echo "_No middleware directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Middleware List"
  find app/Http/Middleware -name "*.php" -type f | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Middleware Details"
  find app/Http/Middleware -name "*.php" -type f | sort | head -8 | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      head -n 60 "$f"
    } | codef php >> "$OUT"
  done

  subheading "🔗 Middleware Registration"
  if [[ -f bootstrap/app.php ]]; then
    {
      echo "// Middleware configuration from bootstrap/app.php"
      grep -A 20 -B 5 'middleware' bootstrap/app.php || head -n 100 bootstrap/app.php
    } | codef php >> "$OUT"
  fi
}

emit_policies() {
  heading "🔐 Policies"

  if [[ ! -d app/Policies ]]; then
    echo "_No policies directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Policies List"
  find app/Policies -name "*.php" -type f | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Policy Details"
  find app/Policies -name "*.php" -type f | sort | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      echo "// Policy methods"
      grep -E 'public\s+function\s+[A-Za-z_]' -n "$f" || true
      echo ""
      head -n 80 "$f"
    } | codef php >> "$OUT"
  done
}

emit_jobs() {
  heading "⚡ Jobs & Queues"

  if [[ ! -d app/Jobs ]]; then
    echo "_No jobs directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Jobs List"
  find app/Jobs -name "*.php" -type f | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Job Details"
  find app/Jobs -name "*.php" -type f | sort | head -10 | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      echo "// Job structure and handle method"
      head -n 80 "$f"
    } | codef php >> "$OUT"
  done

  subheading "⚙️ Queue Configuration"
  if [[ -f config/queue.php ]]; then
    {
      echo "// Queue configuration"
      head -n 50 config/queue.php
    } | codef php >> "$OUT"
  fi
}

emit_events_listeners() {
  heading "📢 Events & Listeners"

  subheading "📣 Events"
  if [[ -d app/Events ]]; then
    find app/Events -name "*.php" -type f | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
      {
        head -n 40 "$f"
      } | codef php >> "$OUT"
    done
  else
    echo "_No events directory found._" >> "$OUT"
  fi

  subheading "👂 Listeners"
  if [[ -d app/Listeners ]]; then
    find app/Listeners -name "*.php" -type f | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
      {
        head -n 40 "$f"
      } | codef php >> "$OUT"
    done
  else
    echo "_No listeners directory found._" >> "$OUT"
  fi

  subheading "🔗 Event Registration"
  if [[ -f app/Providers/EventServiceProvider.php ]]; then
    {
      echo "// Event-Listener mappings"
      head -n 60 app/Providers/EventServiceProvider.php
    } | codef php >> "$OUT"
  fi
}

emit_commands() {
  heading "🖥️ Console Commands"

  if [[ ! -d app/Console/Commands ]]; then
    echo "_No custom commands directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Custom Commands List"
  find app/Console/Commands -name "*.php" -type f | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Command Details"
  find app/Console/Commands -name "*.php" -type f | sort | head -10 | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      echo "// Command signature and description"
      grep -E '\$signature|\$description|public\s+function\s+handle' -n "$f" | head -10 || true
      echo ""
      head -n 80 "$f"
    } | codef php >> "$OUT"
  done
}

emit_api_resources() {
  heading "🔄 API Resources"

  if [[ ! -d app/Http/Resources ]]; then
    echo "_No API resources directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Resources List"
  find app/Http/Resources -name "*.php" -type f | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Resource Details"
  find app/Http/Resources -name "*.php" -type f | sort | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      head -n 60 "$f"
    } | codef php >> "$OUT"
  done
}

emit_migrations() {
  heading "🔄 Migrations"

  if [[ ! -d database/migrations ]]; then
    echo "_No migrations directory found._" >> "$OUT"
    return
  fi

  subheading "📋 All Migrations (chronological)"
  find database/migrations -name "*.php" -type f | sort | while read -r f; do
    echo "- \`$(basename "$f")\`" >> "$OUT"
  done

  subheading "📝 Recent Migrations (last 10)"
  find database/migrations -name "*.php" -type f | sort -r | head -10 | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$(basename "$f")\`"
    {
      head -n 80 "$f"
    } | codef php >> "$OUT"
  done

  subheading "🗄️ Migration Status"
  if command -v $ARTISAN_CMD >/dev/null 2>&1; then
    {
      $ARTISAN_CMD migrate:status 2>/dev/null || echo "Unable to check migration status"
    } | codef txt >> "$OUT"
  fi
}

emit_service_providers() {
  heading "🛠️ Service Providers"

  if [[ ! -d app/Providers ]]; then
    echo "_No providers directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Providers List"
  find app/Providers -name "*.php" -type f | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Provider Details"
  find app/Providers -name "*.php" -type f | sort | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      head -n 80 "$f"
    } | codef php >> "$OUT"
  done

  subheading "🔧 Bootstrap Configuration"
  if [[ -f bootstrap/app.php ]]; then
    {
      echo "// Application bootstrap"
      cat bootstrap/app.php
    } | codef php >> "$OUT"
  fi

  if [[ -f bootstrap/providers.php ]]; then
    {
      echo "// Providers registration"
      cat bootstrap/providers.php
    } | codef php >> "$OUT"
  fi
}

emit_models_overview() {
  heading "🗃️ MODELS & Relationships"

  if [[ ! -d app/Models ]]; then
    echo "_No app/Models directory found._" >> "$OUT"
    return
  fi

  subheading "📊 Models Summary"
  {
    echo "| Model | Table | Relationships |"
    echo "|-------|-------|---------------|"
    find app/Models -maxdepth 1 -name "*.php" | sort | while read -r m; do
      local model_name
      model_name="$(basename "$m" .php)"
      local table_name
      table_name="$(grep 'protected.*\$table' "$m" | sed -E 's/.*=\s*["\x27]([^"\x27]+)["\x27].*/\1/' || echo "N/A")"
      [[ "$table_name" == "N/A" ]] && table_name="${model_name,,}s"
      local rel_count
      rel_count="$(grep -cE 'function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(\)\s*\{[^}]*(hasOne|hasMany|belongsTo|belongsToMany|morphTo|morphMany)' "$m" 2>/dev/null || echo "0")"
      echo "| $model_name | \`$table_name\` | $rel_count |"
    done
  } >> "$OUT"

  subheading "📝 Detailed Model Information"
  find app/Models -maxdepth 1 -name "*.php" | sort | while read -r m; do
    subsubheading "\`$m\`"
    {
      echo "// Model structure: properties and relationships"
      echo ""
      grep -E 'class\s+[A-Za-z_][A-Za-z0-9_]*|protected\s+\$table|protected\s+\$fillable|protected\s+\$casts|protected\s+\$hidden|protected\s+\$guarded|protected\s+\$with|protected\s+\$appends' -n "$m" || true
      echo ""
      echo "// Relationships detected:"
      grep -nE 'function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(\)\s*\{|\$this->(hasOne|hasMany|belongsTo|belongsToMany|morphTo|morphMany|morphToMany|morphedByMany)\(' "$m" || echo "  // No relationships found"
      echo ""
      echo "// Full model code:"
      cat "$m"
    } | codef php >> "$OUT"
  done

  subheading "🔗 Relationship Graph"
  {
    echo "**Model Relationships Map:**"
    echo ""
    find app/Models -maxdepth 1 -name "*.php" | sort | while read -r m; do
      local model_name
      model_name="$(basename "$m" .php)"
      echo "**$model_name:**"
      grep -E 'function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(\)' "$m" | \
        grep -B5 -A5 -E 'hasOne|hasMany|belongsTo|belongsToMany|morphTo' | \
        grep -E 'function\s+[A-Za-z_]|hasOne|hasMany|belongsTo|belongsToMany' | \
        sed 's/^[[:space:]]*/  - /' || echo "  - (no relationships)"
      echo ""
    done
  } >> "$OUT"
}

emit_db_overview() {
  heading "🗄️ DATABASE — Current Schema (live)"
  echo "_Using: $ARTISAN_CMD (Laravel bootstrap + INFORMATION_SCHEMA)._ " >> "$OUT"

  # 1) список таблиц (с безопасным префиксом TAB:)
  local tables_raw
  if ! tables_raw="$(laravel_php '
$rows = DB::select("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = database() ORDER BY 1");
foreach ($rows as $r) { echo "TAB:", $r->t, "\n"; }
' )"; then
    echo "_DB connection unavailable (error during tables listing)._ " >> "$OUT"
    return
  fi

  local tables
  tables="$(printf '%s\n' "$tables_raw" | grep '^TAB:' | sed 's/^TAB://')"
  if [[ -z "$tables" ]]; then
    echo "_No tables found or DB connection unavailable._" >> "$OUT"
    dbg "tables_raw (first 10 lines):"; printf '%s\n' "$tables_raw" | head -10 >&2 || true
    return
  fi

  subheading "📋 Database Tables"
  {
    echo "| # | Table Name | Estimated Rows |"
    echo "|---|------------|----------------|"
    local idx=1
    echo "$tables" | while read -r t; do
      echo "| $idx | \`$t\` | - |"
      ((idx++))
    done
  } >> "$OUT"

  # 2) проходим все таблицы — массив вместо pipe
  set +e
  local -a _tables=()
  while IFS= read -r _line; do
    _line="${_line%$'\r'}"
    [[ -n "$_line" ]] && _tables+=("$_line")
  done <<< "$tables"

  subheading "📊 Detailed Table Schemas"
  for t in "${_tables[@]}"; do
    dbg "table: '$t'"
    subsubheading "Table: \`$t\`"

    # Columns
    echo "" >> "$OUT"
    echo "**Columns:**" >> "$OUT"
    echo "" >> "$OUT"
    {
      echo "| # | Column | Type | Nullable | Default | Key | Extra |"
      echo "|---|--------|------|----------|---------|-----|-------|"
      local cols_out
      cols_out="$( laravel_php '
$t = getenv("CTX_TABLE") ?: "";
$cols = DB::select("SELECT ORDINAL_POSITION AS pos, COLUMN_NAME AS name, COLUMN_TYPE AS ctype, IS_NULLABLE AS isnull, COLUMN_DEFAULT AS dflt, COLUMN_KEY AS k, EXTRA AS extra FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=database() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION", [$t]);
foreach ($cols as $c) {
  printf("| %d | %s | %s | %s | %s | %s | %s |\n", $c->pos, $c->name, $c->ctype, $c->isnull, (string)$c->dflt, $c->k, $c->extra);
}
' "CTX_TABLE=$t" 2>/dev/null )"
      if [[ -z "$cols_out" ]]; then
        echo "_(failed to introspect columns for $t)_"
      else
        printf "%s\n" "$cols_out"
      fi
    } >> "$OUT"

    # FKs
    echo "" >> "$OUT"
    echo "**Foreign Keys:**" >> "$OUT"
    echo "" >> "$OUT"
    {
      echo "| Column | → Table | → Column | Constraint |"
      echo "|--------|---------|----------|------------|"
      local fks_out
      fks_out="$( laravel_php '
$t = getenv("CTX_TABLE") ?: "";
$fks = DB::select("SELECT COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS ref_table, REFERENCED_COLUMN_NAME AS ref_col, CONSTRAINT_NAME AS cname FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=database() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME", [$t]);
foreach ($fks as $f) {
  printf("| %s | %s | %s | %s |\n", $f->col, $f->ref_table, $f->ref_col, $f->cname);
}
' "CTX_TABLE=$t" 2>/dev/null )"
      if [[ -z "$fks_out" ]]; then
        echo "_(no foreign keys or failed to introspect for $t)_"
      else
        printf "%s\n" "$fks_out"
      fi
    } >> "$OUT"

    echo "" >> "$OUT"
    echo "---" >> "$OUT"
  done
  set -e

  # 3) ER summary
  subheading "🔗 Entity-Relationship Summary"
  {
    echo "**All Foreign Key Relationships:**"
    echo ""
  } >> "$OUT"
  laravel_php '
$rels = DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=database() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1,2");
foreach ($rels as $r) {
  printf("- %s.%s → %s.%s\n", $r->t, $r->col, $r->rt, $r->rc);
}
' | codef txt >> "$OUT"
}

emit_nova_resources() {
  heading "👑 Nova Resources"

  if [[ ! -d app/Nova ]]; then
    echo "_No Nova resources directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Nova Resources List"
  find app/Nova -name "*.php" -type f ! -name "Resource.php" ! -name "Dashboard.php" | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Resource Details"
  find app/Nova -name "*.php" -type f ! -name "Resource.php" | sort | while read -r f; do
    subsubheading "\`$f\`"
    {
      echo "// Resource overview"
      grep -E 'class\s+[A-Za-z_][A-Za-z0-9_]*|public\s+static\s+\$model|public\s+static\s+\$title|function\s+fields\(|function\s+cards\(|function\s+filters\(|function\s+actions\(' -n "$f" || true
      echo ""
      echo "// Full content (first 120 lines)"
      head -n 120 "$f"
    } | codef php >> "$OUT"
  done

  subheading "⚡ Nova Actions"
  if [[ -d app/Nova/Actions ]]; then
    find app/Nova/Actions -name "*.php" -type f | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
      head -n 50 "$f" | codef php >> "$OUT"
    done
  else
    echo "_No Nova Actions found._" >> "$OUT"
  fi

  subheading "🔍 Nova Filters"
  if [[ -d app/Nova/Filters ]]; then
    find app/Nova/Filters -name "*.php" -type f | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
      head -n 50 "$f" | codef php >> "$OUT"
    done
  else
    echo "_No Nova Filters found._" >> "$OUT"
  fi

  subheading "🔭 Nova Lenses"
  if [[ -d app/Nova/Lenses ]]; then
    find app/Nova/Lenses -name "*.php" -type f | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
      head -n 50 "$f" | codef php >> "$OUT"
    done
  else
    echo "_No Nova Lenses found._" >> "$OUT"
  fi

  subheading "📊 Nova Dashboards & Metrics"
  if [[ -d app/Nova/Dashboards ]]; then
    find app/Nova/Dashboards -name "*.php" -type f | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
      head -n 50 "$f" | codef php >> "$OUT"
    done
  fi

  if [[ -d app/Nova/Metrics ]]; then
    find app/Nova/Metrics -name "*.php" -type f | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
      head -n 50 "$f" | codef php >> "$OUT"
    done
  fi
}

emit_views_js_tests() {
  heading "🎨 FRONTEND & VIEWS"

  subheading "👁️ Blade Views"
  if [[ -d resources/views ]]; then
    echo "**View Structure:**" >> "$OUT"
    echo "" >> "$OUT"
    find resources/views -name "*.blade.php" -type f | head -20 | sort | while read -r f; do
      echo "- \`$f\`" >> "$OUT"
    done
    echo "" >> "$OUT"

    echo "**Sample Views:**" >> "$OUT"
    find resources/views -name "*.blade.php" -type f | head -8 | while read -r f; do
      [[ -f "$f" ]] || continue
      subsubheading "\`$f\`"
      head -n 50 "$f" | codef html >> "$OUT"
    done
  else
    echo "_No views directory found._" >> "$OUT"
  fi

  subheading "⚡ JavaScript/TypeScript Files"
  if [[ -d resources/js ]]; then
    find resources/js -type f \( -name "*.js" -o -name "*.ts" -o -name "*.vue" -o -name "*.jsx" -o -name "*.tsx" \) | head -15 | sort | while read -r js; do
      [[ -f "$js" ]] || continue
      subsubheading "\`$js\`"
      head -n 60 "$js" | codef javascript >> "$OUT"
    done
  fi

  subheading "🎨 CSS/SCSS Files"
  if [[ -d resources/css ]] || [[ -d resources/sass ]]; then
    find resources/css resources/sass -type f \( -name "*.css" -o -name "*.scss" -o -name "*.sass" \) 2>/dev/null | head -5 | sort | while read -r css; do
      [[ -f "$css" ]] || continue
      subsubheading "\`$css\`"
      head -n 40 "$css" | codef css >> "$OUT"
    done
  fi
}

emit_tests_and_summary() {
  heading "🧪 TESTS"

  if [[ ! -d tests ]]; then
    echo "_No tests directory found._" >> "$OUT"
    return
  fi

  subheading "📋 Test Files"
  find tests -name "*Test.php" -type f | sort | while read -r f; do
    echo "- \`$f\`" >> "$OUT"
  done

  subheading "📝 Sample Tests"
  find tests -name "*Test.php" -type f | head -6 | while read -r f; do
    [[ -f "$f" ]] || continue
    subsubheading "\`$f\`"
    {
      echo "// Test methods:"
      grep -E 'public\s+function\s+test[A-Za-z_]|public\s+function\s+it_' -n "$f" || true
      echo ""
      head -n 50 "$f"
    } | codef php >> "$OUT"
  done

  subheading "🔧 Test Configuration"
  if [[ -f phpunit.xml ]]; then
    {
      cat phpunit.xml
    } | codef xml >> "$OUT"
  fi
}

emit_project_summary() {
  heading "📋 PROJECT SUMMARY"

  subheading "📊 Repository Statistics"
  local files
  if command -v git >/dev/null 2>&1 && git rev-parse --git-dir >/dev/null 2>&1; then
      files=$(git ls-files)
      echo "Using Git repository structure" >> "$OUT"
  else
      files=$(find . -type f | sed 's|^\./||' | grep -Ev "^(\./)?($IGNORE_DIRS)")
      echo "Using file system structure" >> "$OUT"
  fi

  {
    echo ""
    echo "| Metric | Count |"
    echo "|--------|-------|"
    echo "| **Total Files** | $(echo "$files" | wc -l) |"
    echo "| **PHP Files** | $(echo "$files" | grep -c '\.php$' || echo 0) |"
    echo "| **Blade Templates** | $(find resources/views -name "*.blade.php" 2>/dev/null | wc -l) |"
    echo "| **JavaScript Files** | $(echo "$files" | grep -cE '\.(js|ts|vue|jsx|tsx)$' || echo 0) |"
    echo "| **Controllers** | $(find app/Http/Controllers -name "*.php" 2>/dev/null | wc -l) |"
    echo "| **Models** | $(find app/Models -name "*.php" 2>/dev/null | wc -l) |"
    echo "| **Services** | $(find app/Services -name "*.php" 2>/dev/null | wc -l) |"
    echo "| **Migrations** | $(find database/migrations -name "*.php" 2>/dev/null | wc -l) |"
    echo "| **Seeders** | $(find database/seeders -name "*.php" 2>/dev/null | wc -l) |"
    echo "| **Tests** | $(find tests -name "*Test.php" 2>/dev/null | wc -l) |"
    echo "| **Nova Resources** | $(find app/Nova -name "*.php" 2>/dev/null | wc -l) |"
  } >> "$OUT"

  subheading "🏗️ Architecture Summary"
  {
    echo "**Framework & Core:**"
    echo "- Framework: Laravel $(grep -o '"laravel/framework": "[^"]*"' composer.json 2>/dev/null | cut -d'"' -f4 || echo "N/A")"
    echo "- PHP Version: $(php -v 2>/dev/null | head -n 1 | awk '{print $2}' || echo "N/A")"
    echo ""
    echo "**Key Features:**"
    [[ -d app/Nova ]] && echo "- ✅ Laravel Nova Admin Panel"
    [[ -f composer.json ]] && grep -q "laravel/sanctum" composer.json && echo "- ✅ API Authentication (Sanctum)"
    [[ -f composer.json ]] && grep -q "spatie/laravel-permission" composer.json && echo "- ✅ Role & Permission Management"
    [[ -d app/Jobs ]] && echo "- ✅ Queue/Job Processing"
    [[ -d app/Events ]] && echo "- ✅ Event-Driven Architecture"
    [[ -f docker-compose.yml ]] && echo "- ✅ Docker Containerization"
    [[ -f vite.config.js ]] && echo "- ✅ Modern Frontend Build (Vite)"
    [[ -f tailwind.config.js ]] && echo "- ✅ Tailwind CSS"
    echo ""
    echo "**Database:**"
    echo "- Schema introspection via Laravel INFORMATION_SCHEMA"
    echo "- Models with Eloquent ORM"
    echo "- Database migrations managed"
    echo ""
    echo "**Testing:**"
    echo "- Framework: PHPUnit"
    echo "- Test Count: $(find tests -name "*Test.php" 2>/dev/null | wc -l)"
  } >> "$OUT"

  subheading "🔒 Security Notes"
  {
    echo "> ⚠️ **IMPORTANT SECURITY REMINDERS:**"
    echo "> - Always check for sensitive data in .env files before sharing"
    echo "> - Review database credentials and API keys"
    echo "> - Ensure secrets are not committed to version control"
    echo "> - Verify authentication and authorization implementations"
  } >> "$OUT"

  subheading "📅 Generation Info"
  {
    echo "- **Generated:** $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "- **Generated by:** \`context-pack.sh\`"
    echo "- **Working Directory:** \`$(pwd)\`"
    echo "- **Git Branch:** \`$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "N/A")\`"
  } >> "$OUT"

  echo "" >> "$OUT"
  echo "---" >> "$OUT"
  echo "" >> "$OUT"
  echo "✅ **Complete context generated successfully!**" >> "$OUT"
  echo "" >> "$OUT"
  {
    echo "📄 **File:** \`$OUT\`"
    echo "📊 **Size:** $(du -h "$OUT" 2>/dev/null | cut -f1 || echo "N/A")"
    echo "📝 **Lines:** $(wc -l < "$OUT" 2>/dev/null || echo "N/A")"
  } >> "$OUT"
}

# ------------ FILE INSIGHT (single file + related) ------------
emit_file_insight() {
  local input="$1"

  # Resolve: direct path (with \ normalization) → App\Class → basename (with optional .php)
  resolve_path() {
    local arg="$1"
    local norm="${arg//\\//}"
    dbg "resolve: arg='$arg' norm='$norm'"

    # 1) exact path
    if [[ -f "$arg" ]]; then dbg "hit exact path: $arg"; echo "$arg"; return 0; fi
    if [[ -f "$norm" ]]; then dbg "hit exact path norm: $norm"; echo "$norm"; return 0; fi

    # 2) PSR-4 class
    if [[ "$arg" =~ ^App(\\|/) ]] || [[ "$norm" =~ ^App/ ]]; then
      local p
      p="$(printf "%s" "$arg" | sed -E 's#^App[\\/]##; s#[\\/]#/#g')"
      [[ "$p" =~ \.php$ ]] || p="${p}.php"
      p="app/${p}"
      if [[ -f "$p" ]]; then dbg "hit psr4 map: $p"; echo "$p"; return 0; fi
    fi

    # 3) basename search
    local base="$(basename "$norm")"
    local m=""
    if [[ "$base" =~ \.php$ ]]; then
      m="$(git ls-files 2>/dev/null | grep -i "/${base}$" | head -1 || true)"
      [[ -z "$m" ]] && m="$(find . -type f -iname "$base" | head -1 || true)"
    else
      m="$(git ls-files 2>/dev/null | grep -Ei "/${base}(\.php)?$" | head -1 || true)"
      [[ -z "$m" ]] && m="$(find . -type f -iregex ".*/${base}(\.php)?$" | head -1 || true)"
    fi
    dbg "basename search: base='$base' -> '$m'"
    if [[ -n "$m" && -f "$m" ]]; then echo "${m#./}"; return 0; fi
    return 1
  }

  local f
  f="$(resolve_path "$input")" || {
    heading "🧩 File Insight"
    echo -e "**Target file:** \`$input\`" >> "$OUT"
    echo -e "\n> ⚠️ File not found by resolver. Check path/case. Tried 3 strategies (exact, App\\\\Class, basename)." >> "$OUT"
    echo "❌ File not found. Try one of:" >&2
    echo "   ./context-pack.sh file app/Services/LanguageApp/ExamResearchService.php"
    echo "   ./context-pack.sh file App\\Services\\LanguageApp\\ExamResearchService"
    echo "   ./context-pack.sh file ExamResearchService.php"
    exit 1
  }

  heading "🧩 File Insight: \`$f\`"

  subheading "📄 File Information"
  {
    echo "- **Path:** \`$f\`"
    echo "- **Size:** $(du -h "$f" 2>/dev/null | cut -f1 || echo "N/A")"
    echo "- **Lines:** $(wc -l < "$f" 2>/dev/null || echo "N/A")"
    echo "- **Last Modified:** $(stat -c %y "$f" 2>/dev/null || stat -f %Sm "$f" 2>/dev/null || echo "N/A")"
  } >> "$OUT"

  subheading "📝 Full Code"
  cat "$f" | codef "${f##*.}" >> "$OUT"

  subheading "🔗 Detected Dependencies"
  {
    echo "**Direct imports (use statements):**"
    echo ""
    grep -E '^[[:space:]]*use[[:space:]]+' "$f" | sed 's/^[[:space:]]*/- /' || echo "- (no use statements found)"
  } >> "$OUT"

  subheading "📦 Related Files"
  {
    echo "**PSR-4 resolved related files:**"
    echo ""
    grep -E '^[[:space:]]*use[[:space:]]+App\\' "$f" | \
      sed 's/^[[:space:]]*use[[:space:]]\+//' | sed 's/;//' | \
      sed 's#\\#/#g' | sed 's#^App/#app/#' | sed 's#$#.php#'
  } | while read -r line; do
      if [[ "$line" == "**PSR-4 resolved related files:**" ]] || [[ "$line" == "" ]]; then
        echo "$line" >> "$OUT"
        continue
      fi
      local rel="$line"
      if [[ -f "$rel" ]]; then
        subsubheading "Related: \`$rel\`"
        {
          echo "// First 80 lines"
          head -n 80 "$rel"
        } | codef php >> "$OUT"
      fi
    done

  subheading "🎨 Referenced Views"
  {
    echo "**Blade views referenced:**"
    echo ""
    grep -Eo "view\(['\"]([^'\"]+)['\"]" "$f" | sed "s/view(['\"]//; s/['\"].*//" | while read -r v; do
      [[ -z "$v" ]] && continue
      p="resources/views/${v//./\/}.blade.php"
      if [[ -f "$p" ]]; then
        echo "- ✅ \`$v\` → \`$p\` (exists)"
      else
        echo "- ❌ \`$v\` → \`$p\` (not found)"
      fi
    done
  } >> "$OUT"

  subheading "📊 Code Analysis"
  {
    echo "**Code metrics:**"
    echo ""
    echo "- **Classes:** $(grep -c '^class ' "$f" 2>/dev/null || echo 0)"
    echo "- **Methods:** $(grep -cE '^\s+(public|protected|private)\s+function\s+' "$f" 2>/dev/null || echo 0)"
    echo "- **Properties:** $(grep -cE '^\s+(public|protected|private)\s+\$' "$f" 2>/dev/null || echo 0)"
    echo ""
    echo "**Public methods:**"
    echo ""
    grep -nE '^\s+public\s+function\s+[A-Za-z_]' "$f" | sed 's/^/- Line /' || echo "- (none)"
  } >> "$OUT"
}

# =========================
# Orchestration
# =========================
generate_context() {
  emit_repo_header
  emit_table_of_contents

  case "$MODE" in
    metadata)
      emit_project_metadata
      emit_architecture_overview
      ;;
    models)
      emit_models_overview
      ;;
    db)
      emit_db_overview
      ;;
    models+db)
      emit_models_overview
      emit_db_overview
      ;;
    nova)
      emit_nova_resources
      ;;
    file)
      if [[ -z "$TARGET_FILE" ]]; then
        echo "❌ Error: file mode requires a target file path" >&2
        echo "Usage: $0 file <path/to/file>" >&2
        exit 1
      fi
      emit_file_insight "$TARGET_FILE"
      ;;
    all|*)
      emit_project_metadata
      emit_architecture_overview
      emit_file_tree
      emit_core_configs
      emit_routes
      emit_controllers
      emit_services
      emit_middleware
      emit_policies
      emit_jobs
      emit_events_listeners
      emit_commands
      emit_api_resources
      emit_migrations
      emit_service_providers
      emit_models_overview
      emit_db_overview
      emit_nova_resources
      emit_views_js_tests
      emit_tests_and_summary
      emit_project_summary
      ;;
  esac

  echo "✅ Complete context generated: $OUT"
  echo "📊 File size: $(du -h "$OUT" 2>/dev/null | cut -f1 || echo "N/A"), Lines: $(wc -l < "$OUT" 2>/dev/null || echo "N/A")"
}

# =========================
# Main execution
# =========================
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  generate_context
fi
