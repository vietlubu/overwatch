#!/bin/bash

# =========================================
# Development Startup Script
# =========================================
# Khởi động poc-ingest.js và Laravel app cùng lúc
#
# Usage:
#   ./start-dev.sh [laravel-path]
#
# Example:
#   ./start-dev.sh ~/Projects/my-laravel-app
#   ./start-dev.sh

set -e

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
NIGHTWATCH_TOKEN="${NIGHTWATCH_TOKEN:-dev-token}"
HTTP_PORT="${HTTP_PORT:-3000}"
TCP_PORT="${TCP_PORT:-2407}"
LARAVEL_PATH="${1:-}"

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

echo -e "${BLUE}╔════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   Nightwatch POC Development Setup    ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════╝${NC}"
echo ""

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo -e "${RED}✗ Node.js is not installed${NC}"
    echo "Please install Node.js from https://nodejs.org/"
    exit 1
fi

# Check if npm dependencies are installed
if [ ! -d "$SCRIPT_DIR/node_modules" ]; then
    echo -e "${YELLOW}⚠ Installing npm dependencies...${NC}"
    cd "$SCRIPT_DIR"
    npm install
    echo ""
fi

# Function to cleanup on exit
cleanup() {
    echo -e "\n${YELLOW}Shutting down services...${NC}"

    if [ ! -z "$INGEST_PID" ]; then
        kill $INGEST_PID 2>/dev/null || true
        echo -e "${GREEN}✓ Stopped poc-ingest.js${NC}"
    fi

    if [ ! -z "$LARAVEL_PID" ]; then
        kill $LARAVEL_PID 2>/dev/null || true
        echo -e "${GREEN}✓ Stopped Laravel server${NC}"
    fi

    echo -e "${GREEN}Goodbye!${NC}"
    exit 0
}

# Trap Ctrl+C and other termination signals
trap cleanup SIGINT SIGTERM EXIT

# Start poc-ingest.js
echo -e "${YELLOW}Starting poc-ingest.js...${NC}"
cd "$SCRIPT_DIR"
NIGHTWATCH_TOKEN=$NIGHTWATCH_TOKEN HTTP_PORT=$HTTP_PORT TCP_PORT=$TCP_PORT node poc-ingest.js &
INGEST_PID=$!

# Wait for server to start
sleep 2

# Check if poc-ingest.js is still running
if ! ps -p $INGEST_PID > /dev/null; then
    echo -e "${RED}✗ Failed to start poc-ingest.js${NC}"
    exit 1
fi

echo -e "${GREEN}✓ poc-ingest.js started (PID: $INGEST_PID)${NC}"
echo -e "  - TCP Socket: ${BLUE}127.0.0.1:$TCP_PORT${NC}"
echo -e "  - HTTP Server: ${BLUE}http://localhost:$HTTP_PORT${NC}"
echo ""

# Start Laravel if path provided
if [ ! -z "$LARAVEL_PATH" ]; then
    # Check if Laravel path exists
    if [ ! -d "$LARAVEL_PATH" ]; then
        echo -e "${RED}✗ Laravel path not found: $LARAVEL_PATH${NC}"
        exit 1
    fi

    # Check if it's a Laravel project
    if [ ! -f "$LARAVEL_PATH/artisan" ]; then
        echo -e "${RED}✗ Not a Laravel project: $LARAVEL_PATH${NC}"
        exit 1
    fi

    echo -e "${YELLOW}Starting Laravel server...${NC}"
    cd "$LARAVEL_PATH"

    # Check Laravel configuration
    if ! grep -q "NIGHTWATCH_ENABLED=true" .env 2>/dev/null; then
        echo -e "${YELLOW}⚠ Warning: NIGHTWATCH_ENABLED not set to true in .env${NC}"
        echo -e "  Add the following to your .env file:"
        echo -e "  ${BLUE}NIGHTWATCH_ENABLED=true${NC}"
        echo -e "  ${BLUE}NIGHTWATCH_TOKEN=$NIGHTWATCH_TOKEN${NC}"
        echo -e "  ${BLUE}NIGHTWATCH_INGEST_URI=127.0.0.1:$TCP_PORT${NC}"
        echo -e "  ${BLUE}NIGHTWATCH_BASE_URL=http://localhost:$HTTP_PORT${NC}"
        echo ""
    fi

    # Clear Laravel cache
    php artisan config:clear > /dev/null 2>&1 || true

    # Start Laravel server
    php artisan serve > /dev/null 2>&1 &
    LARAVEL_PID=$!

    # Wait for Laravel to start
    sleep 2

    # Check if Laravel is still running
    if ! ps -p $LARAVEL_PID > /dev/null; then
        echo -e "${RED}✗ Failed to start Laravel server${NC}"
        exit 1
    fi

    echo -e "${GREEN}✓ Laravel server started (PID: $LARAVEL_PID)${NC}"
    echo -e "  - URL: ${BLUE}http://127.0.0.1:8000${NC}"
    echo ""
else
    echo -e "${YELLOW}⚠ Laravel path not provided${NC}"
    echo -e "  To also start Laravel, run:"
    echo -e "  ${BLUE}./start-dev.sh /path/to/laravel/project${NC}"
    echo ""
fi

# Test connection
echo -e "${YELLOW}Testing connection...${NC}"
sleep 1
node "$SCRIPT_DIR/test-connection.js" 2>&1 | tail -n 5

echo ""
echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║         All Services Running!          ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════╝${NC}"
echo ""
echo -e "Services:"
echo -e "  ${GREEN}✓${NC} poc-ingest.js - ${BLUE}http://localhost:$HTTP_PORT${NC}"
echo -e "  ${GREEN}✓${NC} TCP Socket - ${BLUE}127.0.0.1:$TCP_PORT${NC}"

if [ ! -z "$LARAVEL_PID" ]; then
    echo -e "  ${GREEN}✓${NC} Laravel App - ${BLUE}http://127.0.0.1:8000${NC}"
    echo ""
    echo -e "Try these commands:"
    echo -e "  ${BLUE}curl http://127.0.0.1:8000${NC}"
    echo -e "  ${BLUE}cd $LARAVEL_PATH && php artisan inspire${NC}"
fi

echo ""
echo -e "Configuration:"
echo -e "  Token: ${BLUE}$NIGHTWATCH_TOKEN${NC}"
echo ""
echo -e "${YELLOW}Press Ctrl+C to stop all services${NC}"
echo ""

# Wait indefinitely
wait
