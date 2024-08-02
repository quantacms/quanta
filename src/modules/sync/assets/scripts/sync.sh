#!/bin/bash

set -e

# Detect the operating system
OS=$(uname -s)

# Determine the correct getopt command
if [[ "$OS" == "Darwin" ]]; then
  # macOS
  if ! command -v getopt > /dev/null 2>&1; then
    echo "GNU getopt is not installed. Please install it using 'brew install gnu-getopt'."
    exit 1
  fi
  GETOPT=$(brew --prefix gnu-getopt)/bin/getopt
else
  # Linux and other Unix-like OS
  GETOPT=$(command -v getopt)
fi

OPTIONS=$($GETOPT -o e:u: -l env:,user:,ssh-key:,override,override-recent:,help -n 'sync.sh' -- "$@")
eval set -- "$OPTIONS"

USER="root"  # Default user

while true; do
  case "$1" in
    --env ) ENV="$2"; shift 2 ;;
    --user ) USER="$2"; shift 2 ;;
    --ssh-key ) SSH_KEY="$2"; shift 2 ;;
    --override ) OVERRIDE=true; shift ;;
    --override-recent ) OVERRIDE_RECENT="$2"; shift 2 ;;
    -h | --help )
      printf "Usage: %s [options]\n" $0
      printf "Options:\n"
      printf "  --env=<dev|stage|prod>          Specify the environment (dev, stage, or prod)\n"
      printf "  --user=<username>               Specify the SSH username (default: root)\n"
      printf "  --ssh-key=<path>                Path to the SSH key for authentication\n"
      printf "  --override                      Use the --delete option with rsync to delete files in the destination that are not in the source\n"
      printf "  --override-recent=<time>        Only sync files modified within the specified time (e.g., 24h for the last 24 hours)\n"
      printf "  -h, --help                      Display this help message and exit\n"
      printf "\nExamples:\n"
      printf "  ./sync.sh --env dev --user myuser --ssh-key /path/to/ssh-key\n"
      printf "  ./sync.sh --env stage --user myuser --ssh-key /path/to/ssh-key --override\n"
      printf "  ./sync.sh --env prod --user myuser --ssh-key /path/to/ssh-key --override-recent 24h\n"
      exit 0
      ;;
    -- ) shift; break ;;
    * ) break ;;
  esac
done

# Check if rsync is installed
if ! [ -x "$(command -v rsync)" ]; then
  echo "rsync is not installed"
  exit 1
fi

# Set the server and source directories based on the environment
case "$ENV" in
  dev)
    SERVER="212.71.254.132"
    SOURCE_DIRS=("$USER@$SERVER:/var/www/quanta/sites/walltips-dev/db/" "$USER@$SERVER:/var/www/quanta/sites/walltips-dev/db/_users/" "$USER@$SERVER:/var/www/quanta/sites/walltips-dev/db/_translations/")
    ;;
  stage)
    SERVER="212.71.254.132"
    SOURCE_DIRS=("$USER@$SERVER:/var/www/quanta/sites/walltips-stage/db/" "$USER@$SERVER:/var/www/quanta/sites/walltips-stage/db/_users/" "$USER@$SERVER:/var/www/quanta/sites/walltips-stage/db/_translations/")
    ;;
  prod)
    SERVER="172.232.218.137"
    SOURCE_DIRS=("$USER@$SERVER:/var/www/quanta/sites/walltips-prod/db/" "$USER@$SERVER:/var/www/quanta/sites/walltips-prod/db/_users/" "$USER@$SERVER:/var/www/quanta/sites/walltips-prod/db/_translations/")
    ;;
  *)
    echo "Invalid environment specified $ENV. Use dev, stage, or prod."
    exit 1
    ;;
esac

# Go to git root directory
cd "$(git rev-parse --show-toplevel)" || exit 1

# Define the destination base directory on the local machine
DEST_BASE_DIR="$(pwd)"

# Prepare the rsync options
RSYNC_OPTS="-avz"

if [ "$OVERRIDE" = true ]; then
  RSYNC_OPTS="$RSYNC_OPTS --delete"
fi

if [ -n "$OVERRIDE_RECENT" ]; then
  RSYNC_OPTS="$RSYNC_OPTS --max-age=$OVERRIDE_RECENT"
fi

# Define the destination directories
DEST_DIRS=("$DEST_BASE_DIR/db/" "$DEST_BASE_DIR/db/_users/" "$DEST_BASE_DIR/db/_translations/")

# Sync directories from the server using rsync with optional SSH key
for i in "${!SOURCE_DIRS[@]}"; do
  SOURCE_DIR="${SOURCE_DIRS[$i]}"
  DEST_DIR="${DEST_DIRS[$i]}"
  if [ -n "$SSH_KEY" ]; then
    RSYNC_CMD="rsync $RSYNC_OPTS -e 'ssh -i $SSH_KEY' $SOURCE_DIR $DEST_DIR"
  else
    RSYNC_CMD="rsync $RSYNC_OPTS $SOURCE_DIR $DEST_DIR"
  fi

  echo "Starting synchronization for $SOURCE_DIR..."
  echo "Running command: $RSYNC_CMD"
  $RSYNC_CMD

  if [ $? -eq 0 ]; then
    echo "Synchronization for $SOURCE_DIR completed successfully."
  else
    echo "Synchronization for $SOURCE_DIR failed."
    exit 1
  fi
done

echo "All synchronizations completed successfully."
