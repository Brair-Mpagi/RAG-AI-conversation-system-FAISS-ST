#!/bin/bash
# Campus Query AI Assistant - Setup Verification Script
# Checks if everything is properly configured before first run

set -e

PROJECT_ROOT="/run/media/bcodz/Bcodz/End_of_Sem_project/Campus_Query_AI_Assistant"
BACKEND_DIR="$PROJECT_ROOT/backend"
WEB_DIR="$PROJECT_ROOT/interfaces/Ai_web/react"

PASS="\033[0;32m✓\033[0m"
FAIL="\033[0;31m✗\033[0m"
WARN="\033[0;33m⚠\033[0m"
INFO="\033[0;36mℹ\033[0m"

echo "========================================="
echo "Campus AI Setup Verification"
echo "========================================="
echo ""

CHECKS_PASSED=0
CHECKS_FAILED=0
CHECKS_WARNING=0

# Function to check and report
check() {
    local description="$1"
    local command="$2"
    
    if eval "$command" > /dev/null 2>&1; then
        echo -e "$PASS $description"
        ((CHECKS_PASSED++))
        return 0
    else
        echo -e "$FAIL $description"
        ((CHECKS_FAILED++))
        return 1
    fi
}

check_warn() {
    local description="$1"
    local command="$2"
    
    if eval "$command" > /dev/null 2>&1; then
        echo -e "$PASS $description"
        ((CHECKS_PASSED++))
        return 0
    else
        echo -e "$WARN $description"
        ((CHECKS_WARNING++))
        return 1
    fi
}

echo "1. System Prerequisites"
echo "─────────────────────────────────────────"
check "Python 3.11+ installed" "command -v python3.11"
check "Python 3 installed" "command -v python3"
check "Node.js installed" "command -v node"
check "npm installed" "command -v npm"
check "Git installed" "command -v git"
check "MySQL client installed" "command -v mysql"
echo ""

echo "2. Project Structure"
echo "─────────────────────────────────────────"
check "Backend directory exists" "[ -d '$BACKEND_DIR' ]"
check "Web interface directory exists" "[ -d '$WEB_DIR' ]"
check "Scripts directory exists" "[ -d '$PROJECT_ROOT/scripts' ]"
check "Docs directory exists" "[ -d '$PROJECT_ROOT/docs' ]"
check "Database directory exists" "[ -d '$PROJECT_ROOT/database' ]"
check "Logs directory exists" "[ -d '$PROJECT_ROOT/logs' ]"
echo ""

echo "3. Backend Configuration"
echo "─────────────────────────────────────────"
check "Backend .env exists" "[ -f '$BACKEND_DIR/.env' ]"
check "requirements.txt exists" "[ -f '$BACKEND_DIR/requirements.txt' ]"
check "main.py exists" "[ -f '$BACKEND_DIR/main.py' ]"
check_warn "Backend venv exists" "[ -d '$BACKEND_DIR/venv' ]"

if [ -f "$BACKEND_DIR/.env" ]; then
    if grep -q "replace-with-a-secure-32char-key" "$BACKEND_DIR/.env"; then
        echo -e "$FAIL SECRET_KEY needs to be changed"
        ((CHECKS_FAILED++))
    else
        echo -e "$PASS SECRET_KEY is configured"
        ((CHECKS_PASSED++))
    fi
fi
echo ""

echo "4. Web Interface Configuration"
echo "─────────────────────────────────────────"
check "React package.json exists" "[ -f '$WEB_DIR/package.json' ]"
check "React .env exists" "[ -f '$WEB_DIR/.env' ]"
check "Vite config exists" "[ -f '$WEB_DIR/vite.config.ts' ]"
check_warn "node_modules exists" "[ -d '$WEB_DIR/node_modules' ]"
echo ""

echo "5. XAMPP Services"
echo "─────────────────────────────────────────"
if pgrep -x "mysqld" > /dev/null; then
    echo -e "$PASS MySQL is running"
    ((CHECKS_PASSED++))
else
    echo -e "$FAIL MySQL is not running"
    echo -e "   $INFO Run: sudo /opt/lampp/lampp startmysql"
    ((CHECKS_FAILED++))
fi

if pgrep -x "httpd" > /dev/null; then
    echo -e "$PASS Apache is running"
    ((CHECKS_PASSED++))
else
    echo -e "$WARN Apache is not running (optional)"
    echo -e "   $INFO Run: sudo /opt/lampp/lampp startapache"
    ((CHECKS_WARNING++))
fi
echo ""

echo "6. Ollama AI Service"
echo "─────────────────────────────────────────"
if pgrep -x "ollama" > /dev/null; then
    echo -e "$PASS Ollama service is running"
    ((CHECKS_PASSED++))
else
    echo -e "$FAIL Ollama is not running"
    echo -e "   $INFO Run: ollama serve &"
    ((CHECKS_FAILED++))
fi

if command -v ollama > /dev/null 2>&1; then
    MODEL_COUNT=$(ollama list 2>/dev/null | grep -c "llama\|tinyllama" || echo "0")
    if [ "$MODEL_COUNT" -ge 1 ]; then
        echo -e "$PASS Ollama models installed ($MODEL_COUNT found)"
        ((CHECKS_PASSED++))
    else
        echo -e "$FAIL No Ollama models found"
        echo -e "   $INFO Run: ollama pull llama3.2:3b-instruct-q4_K_M"
        ((CHECKS_FAILED++))
    fi
fi
echo ""

echo "7. Knowledge Base"
echo "─────────────────────────────────────────"
CONTENT_FILES=$(find "$BACKEND_DIR/static_data" -type f ! -name ".gitkeep" 2>/dev/null | wc -l)
if [ "$CONTENT_FILES" -gt 0 ]; then
    echo -e "$PASS MMU content files exist ($CONTENT_FILES files)"
    ((CHECKS_PASSED++))
else
    echo -e "$WARN No MMU content files found"
    echo -e "   $INFO Add content to backend/static_data/"
    ((CHECKS_WARNING++))
fi

if [ -f "$BACKEND_DIR/vector_store/campus_knowledge.faiss" ]; then
    echo -e "$PASS FAISS vector store exists"
    ((CHECKS_PASSED++))
else
    echo -e "$WARN FAISS vector store not built"
    echo -e "   $INFO Run: python3 scripts/build_vector_store.py"
    ((CHECKS_WARNING++))
fi
echo ""

echo "8. Database"
echo "─────────────────────────────────────────"
if command -v mysql > /dev/null 2>&1; then
    if mysql --socket=/opt/lampp/var/mysql/mysql.sock --skip-ssl -u campus_ai_user -proot -e "USE campus_ai_db; SELECT 1;" > /dev/null 2>&1; then
        echo -e "$PASS Database 'campus_ai_db' accessible"
        ((CHECKS_PASSED++))
        
        TABLE_COUNT=$(mysql --socket=/opt/lampp/var/mysql/mysql.sock --skip-ssl -u campus_ai_user -proot campus_ai_db -e "SHOW TABLES;" 2>/dev/null | wc -l)
        TABLE_COUNT=$((TABLE_COUNT - 1))  # Subtract header
        
        if [ "$TABLE_COUNT" -ge 10 ]; then
            echo -e "$PASS Database tables exist ($TABLE_COUNT tables)"
            ((CHECKS_PASSED++))
        else
            echo -e "$FAIL Database tables missing ($TABLE_COUNT found, need 16)"
            echo -e "   $INFO Run: mysql ... < database/schema.sql"
            ((CHECKS_FAILED++))
        fi
    else
        echo -e "$FAIL Cannot access database 'campus_ai_db'"
        echo -e "   $INFO Import schema: mysql ... < database/schema.sql"
        ((CHECKS_FAILED++))
    fi
fi
echo ""

echo "9. Scripts & Permissions"
echo "─────────────────────────────────────────"
check "start_dev.sh exists" "[ -f '$PROJECT_ROOT/scripts/start_dev.sh' ]"
check "stop_dev.sh exists" "[ -f '$PROJECT_ROOT/scripts/stop_dev.sh' ]"
check "build_vector_store.py exists" "[ -f '$PROJECT_ROOT/scripts/build_vector_store.py' ]"

if [ -f "$PROJECT_ROOT/scripts/start_dev.sh" ]; then
    if [ -x "$PROJECT_ROOT/scripts/start_dev.sh" ]; then
        echo -e "$PASS Scripts are executable"
        ((CHECKS_PASSED++))
    else
        echo -e "$WARN Scripts are not executable"
        echo -e "   $INFO Run: chmod +x scripts/*.sh scripts/*.py"
        ((CHECKS_WARNING++))
    fi
fi
echo ""

echo "========================================="
echo "Verification Summary"
echo "========================================="
echo -e "✓ Passed:  $CHECKS_PASSED"
echo -e "✗ Failed:  $CHECKS_FAILED"
echo -e "⚠ Warnings: $CHECKS_WARNING"
echo ""

if [ $CHECKS_FAILED -eq 0 ]; then
    echo -e "\033[0;32m✅ Setup verification PASSED!\033[0m"
    echo ""
    echo "You're ready to start the development environment:"
    echo "  ./scripts/start_dev.sh"
    echo ""
    exit 0
else
    echo -e "\033[0;31m❌ Setup verification FAILED!\033[0m"
    echo ""
    echo "Please fix the issues above before running."
    echo "See docs/deployment/DEVELOPMENT_SETUP.md for help."
    echo ""
    exit 1
fi
