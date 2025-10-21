#!/usr/bin/env bash
set -euo pipefail

# ===========================
# Redmine bulk upload (issues + time entries) for "ExamHelp"
# Requirements: curl, jq; Redmine REST API enabled; your API key has permissions to create issues and log time.
#
# USAGE:
#   export REDMINE_URL="https://redmine.example.com"
#   export REDMINE_API_KEY="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
#   export PROJECT_IDENTIFIER="examhelp"        # Redmine project identifier (not the name)
#   export TRACKER_NAME="Task"                  # Optional; defaults to first tracker if not found
#   export DRY_RUN="false"                      # Set to "true" to preview without sending
#   bash redmine_bulk_upload.sh
# ===========================

REDMINE_URL="${REDMINE_URL:?Set REDMINE_URL e.g., https://redmine.example.com}"
API_KEY="${REDMINE_API_KEY:?Set REDMINE_API_KEY from Redmine → My account → API access key}"
PROJECT_IDENTIFIER="${PROJECT_IDENTIFIER:-examhelp}"
TRACKER_NAME="${TRACKER_NAME:-Task}"
DRY_RUN="${DRY_RUN:-false}"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1"; exit 1; }; }
need curl
need jq

api_get()  { curl -sS -H "X-Redmine-API-Key: $API_KEY" "$REDMINE_URL$1"; }
api_post() { curl -sS -H "X-Redmine-API-Key: $API_KEY" -H "Content-Type: application/json" -X POST -d "$2" "$REDMINE_URL$1"; }

echo "→ Resolving project id for identifier: $PROJECT_IDENTIFIER"
PID=$(api_get "/projects/${PROJECT_IDENTIFIER}.json" | jq -r '.project.id')
if [[ -z "$PID" || "$PID" == "null" ]]; then
  echo "✗ Could not resolve project id for identifier: $PROJECT_IDENTIFIER"
  exit 1
fi
echo "✓ Project id: $PID"

echo "→ Fetching trackers"
TRACKERS=$(api_get "/trackers.json")
TRACKER_ID=$(echo "$TRACKERS" | jq -r --arg name "$TRACKER_NAME" '.trackers[] | select(.name==$name) | .id' || true)
if [[ -z "$TRACKER_ID" ]]; then
  TRACKER_ID=$(echo "$TRACKERS" | jq -r '.trackers[0].id')
  echo "! TRACKER_NAME '$TRACKER_NAME' not found. Using first tracker id: $TRACKER_ID"
else
  echo "✓ Tracker '$TRACKER_NAME' id: $TRACKER_ID"
fi

echo "→ Fetching time entry activities"
ACTIVITIES=$(api_get "/enumerations/time_entry_activities.json")
get_activity_id() {
  local name="$1"
  local id
  id=$(echo "$ACTIVITIES" | jq -r --arg n "$name" '.time_entry_activities[] | select(.name==$n) | .id' || true)
  if [[ -z "$id" || "$id" == "null" ]]; then
    # fallback to a common default
    id=$(echo "$ACTIVITIES" | jq -r '.time_entry_activities[] | select(.name=="Development") | .id' || true)
  fi
  echo "$id"
}

# ---- Define issues to create (subjects + descriptions) ----
declare -A SUBJECT_TO_DESC
SUBJECT_TO_DESC["Tailscale access & SSH connectivity"]="Invite acceptance, tailnet visibility, SSH timeout troubleshooting (no matching peer)."
SUBJECT_TO_DESC["DNS & Netplan fixes"]="Resolved DNS; fixed resolv.conf & netplan permissions; applied config; verified resolution."
SUBJECT_TO_DESC["Docker CE install & permissions"]="Installed Docker CE; enabled service; added user to docker group; verified docker/compose."
SUBJECT_TO_DESC["Compose stack & Composer install (Nova auth triage)"]="Brought up docker-compose; composer install; git safe.directory; permissions; Nova 403 triage."
SUBJECT_TO_DESC["APP_URL/session config & CSRF fix (Nova login)"]="Adjusted APP_URL & session settings; cleared caches; fixed docker-compose env overrides; verified via Tinker; login OK."

# ---- Create issues and store their IDs ----
declare -A ISSUE_IDS
echo "→ Creating issues"
for subj in "${!SUBJECT_TO_DESC[@]}"; do
  desc="${SUBJECT_TO_DESC[$subj]}"
  payload=$(jq -n \
    --arg pid "$PID" \
    --arg subj "$subj" \
    --arg desc "$desc" \
    --argjson tracker_id "$TRACKER_ID" \
    '{issue:{project_id:($pid|tonumber), tracker_id:$tracker_id, subject:$subj, description:$desc}}')
  if [[ "$DRY_RUN" == "true" ]]; then
    echo "DRY: would create issue: $subj"
  else
    resp=$(api_post "/issues.json" "$payload")
    id=$(echo "$resp" | jq -r '.issue.id')
    if [[ -z "$id" || "$id" == "null" ]]; then
      echo "✗ Failed to create issue for \"$subj\": $resp"
      exit 1
    fi
    ISSUE_IDS["$subj"]="$id"
    echo "✓ Created issue #$id: $subj"
  fi
done

# ---- Define time entries to log ----
read -r -d '' TIME_ENTRIES_JSON <<'JSON'
[
  {"date":"2025-10-16","hours":0.5,"subject":"Tailscale access & SSH connectivity","activity":"Development","comment":"Tailscale invite accepted; initial SSH attempts; verified tailnet visibility (no matching peer)."},
  {"date":"2025-10-17","hours":0.5,"subject":"DNS & Netplan fixes","activity":"Development","comment":"DNS broken on server → fixed resolv.conf + netplan perms; applied; verified name resolution."},
  {"date":"2025-10-17","hours":0.4,"subject":"Docker CE install & permissions","activity":"Development","comment":"Installed Docker CE; enabled service; added user to docker group; verified docker/compose versions."},
  {"date":"2025-10-17","hours":0.9,"subject":"Compose stack & Composer install (Nova auth triage)","activity":"Development","comment":"Brought up compose; composer install; fixed git safe.directory + permissions; handled Nova auth error triage."},
  {"date":"2025-10-17","hours":1.0,"subject":"APP_URL/session config & CSRF fix (Nova login)","activity":"Development","comment":"APP_URL & session config; cleared caches; fixed docker-compose env overrides; Tinker checks; CSRF mismatch resolved; login works."}
]
JSON

echo "→ Creating time entries"
echo "$TIME_ENTRIES_JSON" | jq -c '.[]' | while read -r row; do
  date=$(echo "$row"   | jq -r '.date')
  hours=$(echo "$row"  | jq -r '.hours')
  subject=$(echo "$row"| jq -r '.subject')
  activity=$(echo "$row"| jq -r '.activity')
  comment=$(echo "$row"| jq -r '.comment')

  issue_id="${ISSUE_IDS[$subject]:-}"
  activity_id="$(get_activity_id "$activity")"

  payload=$(jq -n \
    --arg pid "$PID" \
    --arg iid "$issue_id" \
    --arg date "$date" \
    --arg hours "$hours" \
    --arg aid "$activity_id" \
    --arg c "$comment" \
    '{
      time_entry:{
        project_id:($pid|tonumber),
        spent_on:$date,
        hours:($hours|tonumber),
        comments:$c
      }
    }
    | if $iid != "" then .time_entry.issue_id = ($iid|tonumber) else . end
    | if $aid != "" then .time_entry.activity_id = ($aid|tonumber) else . end
    ')

  if [[ "$DRY_RUN" == "true" ]]; then
    echo "DRY: would log $hours h on $date (issue_id=${issue_id:-none}, activity_id=${activity_id:-auto})"
  else
    resp=$(api_post "/time_entries.json" "$payload")
    tid=$(echo "$resp" | jq -r '.time_entry.id')
    if [[ -z "$tid" || "$tid" == "null" ]]; then
      echo "✗ Failed to create time entry for $date: $resp"
      exit 1
    fi
    echo "✓ Logged time_entry #$tid ($hours h) for $date (issue_id=${issue_id:-none})"
  fi
done

echo "All done ✅"
