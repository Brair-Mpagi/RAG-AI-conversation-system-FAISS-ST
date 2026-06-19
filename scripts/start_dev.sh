#!/bin/bash
# Campus Query AI Assistant - Development Environment Startup Script

set -e  # Exit on error

PROJECT_ROOT="/run/media/bcodz/Bcodz/End_of_Sem_project/Campus_Query_AI_Assistant"
BACKEND_DIR="$PROJECT_ROOT/backend"
WEB_DIR="$PROJECT_ROOT/interfaces/Ai_web/react"

echo "========================================="
echo "Campus AI Development Environment"
echo "========================================="
echo ""

# Check if XAMPP is running
echo "📦 Checking XAMPP Services..."
if ! pgrep -x "mysqld" > /dev/null; then
    echo "⚠️  MySQL not running. Starting XAMPP..."
    sudo /opt/lampp/lampp startmysql
    sleep 3
else
    echo "✅ MySQL is running"
fi

# Check if Ollama is running
echo ""
echo "🤖 Checking Ollama Service..."
if ! pgrep -x "ollama" > /dev/null; then
    echo "⚠️  Ollama not running. Starting..."
    ollama serve > /dev/null 2>&1 &
    sleep 3
    echo "✅ Ollama started"
else
    echo "✅ Ollama is running"
fi

# Start Backend
echo ""
echo "🚀 Starting Backend Server..."
cd "$BACKEND_DIR"

# Check for venv
if [ ! -d "venv" ]; then
    echo "❌ Backend venv not found. Please run setup first:"
    echo "   cd backend && python3.11 -m venv venv && source venv/bin/activate && pip install -r requirements.txt"
    exit 1
fi

source venv/bin/activate
python main.py > ../logs/backend.log 2>&1 &
BACKEND_PID=$!
deactivate

echo "✅ Backend started (PID: $BACKEND_PID)"
echo "   API: http://localhost:8000"
echo "   Docs: http://localhost:8000/docs"

# Start Web Interface
echo ""
echo "🌐 Starting Web Interface..."
cd "$WEB_DIR"

if [ ! -d "node_modules" ]; then
    echo "⚠️  node_modules not found. Installing dependencies..."
    npm install
fi

npm run dev > "$PROJECT_ROOT/logs/web.log" 2>&1 &
WEB_PID=$!

echo "✅ Web Interface started (PID: $WEB_PID)"
echo "   URL: http://localhost:5173"
echo "   External: http://10.43.96.151:5173"

# Save PIDs for cleanup
echo "$BACKEND_PID" > "$PROJECT_ROOT/.backend.pid"
echo "$WEB_PID" > "$PROJECT_ROOT/.web.pid"

echo ""
echo "========================================="
echo "✅ Development Environment Started!"
echo "========================================="
echo ""
echo "Services Running:"
echo "  • MySQL (XAMPP): http://localhost/phpmyadmin"
echo "  • Ollama: http://localhost:11434"
echo "  • Backend API: http://localhost:8000"
echo "  • Web Interface: http://localhost:5173"
echo "  • Admin Panel: http://localhost/Admin-F/"
echo ""
echo "Logs:"
echo "  • Backend: $PROJECT_ROOT/logs/backend.log"
echo "  • Web: $PROJECT_ROOT/logs/web.log"
echo ""
echo "To stop all services, run:"
echo "  ./scripts/stop_dev.sh"
echo ""
