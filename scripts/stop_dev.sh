#!/bin/bash
# Campus Query AI Assistant - Stop Development Environment

PROJECT_ROOT="/run/media/bcodz/Bcodz/End_of_Sem_project/Campus_Query_AI_Assistant"

echo "========================================="
echo "Stopping Campus AI Development Environment"
echo "========================================="
echo ""

# Stop Backend
if [ -f "$PROJECT_ROOT/.backend.pid" ]; then
    BACKEND_PID=$(cat "$PROJECT_ROOT/.backend.pid")
    if ps -p $BACKEND_PID > /dev/null 2>&1; then
        echo "🛑 Stopping Backend (PID: $BACKEND_PID)..."
        kill $BACKEND_PID
        rm "$PROJECT_ROOT/.backend.pid"
        echo "✅ Backend stopped"
    else
        echo "⚠️  Backend process not found"
        rm "$PROJECT_ROOT/.backend.pid"
    fi
else
    echo "⚠️  No backend PID file found"
    # Try to kill any running main.py process
    pkill -f "python main.py" && echo "✅ Killed backend processes" || echo "ℹ️  No backend process running"
fi

# Stop Web Interface
if [ -f "$PROJECT_ROOT/.web.pid" ]; then
    WEB_PID=$(cat "$PROJECT_ROOT/.web.pid")
    if ps -p $WEB_PID > /dev/null 2>&1; then
        echo "🛑 Stopping Web Interface (PID: $WEB_PID)..."
        kill $WEB_PID
        rm "$PROJECT_ROOT/.web.pid"
        echo "✅ Web Interface stopped"
    else
        echo "⚠️  Web process not found"
        rm "$PROJECT_ROOT/.web.pid"
    fi
else
    echo "⚠️  No web PID file found"
    # Try to kill any running vite process
    pkill -f "vite" && echo "✅ Killed Vite processes" || echo "ℹ️  No Vite process running"
fi

echo ""
echo "========================================="
echo "✅ Development Environment Stopped"
echo "========================================="
echo ""
echo "Note: XAMPP and Ollama services are still running."
echo "To stop them manually:"
echo "  • XAMPP: sudo /opt/lampp/lampp stop"
echo "  • Ollama: pkill ollama"
echo ""
