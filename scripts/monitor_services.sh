#!/bin/bash
# Campus Query AI Assistant - Service Monitoring Script

echo "========================================="
echo "Campus AI Service Monitor"
echo "========================================="
echo "Timestamp: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# XAMPP Services
echo "📦 XAMPP Services"
echo "─────────────────────────────────────────"
if pgrep -x "mysqld" > /dev/null; then
    echo "✅ MySQL: Running"
    MYSQL_PID=$(pgrep -x "mysqld")
    MYSQL_MEM=$(ps -p $MYSQL_PID -o %mem | tail -1 | xargs)
    echo "   PID: $MYSQL_PID | Memory: ${MYSQL_MEM}%"
else
    echo "❌ MySQL: Not Running"
fi

if pgrep -x "httpd" > /dev/null; then
    echo "✅ Apache: Running"
    APACHE_PID=$(pgrep -x "httpd" | head -1)
    echo "   PID: $APACHE_PID"
else
    echo "❌ Apache: Not Running"
fi
echo ""

# Ollama Service
echo "🤖 Ollama AI Service"
echo "─────────────────────────────────────────"
if pgrep -x "ollama" > /dev/null; then
    echo "✅ Ollama: Running"
    OLLAMA_PID=$(pgrep -x "ollama")
    OLLAMA_MEM=$(ps -p $OLLAMA_PID -o %mem | tail -1 | xargs)
    echo "   PID: $OLLAMA_PID | Memory: ${OLLAMA_MEM}%"
    
    # Check Ollama API
    if curl -s http://localhost:11434/api/version > /dev/null 2>&1; then
        echo "   API: ✅ Responding"
    else
        echo "   API: ❌ Not Responding"
    fi
else
    echo "❌ Ollama: Not Running"
fi
echo ""

# Backend Service
echo "🚀 Backend API Service"
echo "─────────────────────────────────────────"
if pgrep -f "python main.py" > /dev/null; then
    echo "✅ Backend: Running"
    BACKEND_PID=$(pgrep -f "python main.py")
    BACKEND_MEM=$(ps -p $BACKEND_PID -o %mem | tail -1 | xargs)
    echo "   PID: $BACKEND_PID | Memory: ${BACKEND_MEM}%"
    
    # Check Backend API
    if curl -s http://localhost:8000/ > /dev/null 2>&1; then
        echo "   API: ✅ Responding"
        echo "   URL: http://localhost:8000"
    else
        echo "   API: ❌ Not Responding"
    fi
else
    echo "❌ Backend: Not Running"
fi
echo ""

# Web Interface
echo "🌐 Web Interface"
echo "─────────────────────────────────────────"
if pgrep -f "vite" > /dev/null; then
    echo "✅ Vite Dev Server: Running"
    VITE_PID=$(pgrep -f "vite")
    echo "   PID: $VITE_PID"
    echo "   URL: http://localhost:5173"
else
    echo "❌ Vite Dev Server: Not Running"
fi
echo ""

# Port Usage
echo "🔌 Port Usage"
echo "─────────────────────────────────────────"
sudo netstat -tulpn 2>/dev/null | grep -E "(:80|:3306|:8000|:5173|:11434)" | while read line; do
    PORT=$(echo $line | awk '{print $4}' | rev | cut -d: -f1 | rev)
    PROGRAM=$(echo $line | awk '{print $7}' | cut -d/ -f2)
    echo "   Port $PORT: $PROGRAM"
done
echo ""

# Disk Usage
echo "💾 Disk Usage"
echo "─────────────────────────────────────────"
PROJECT_DIR="/run/media/bcodz/Bcodz/End_of_Sem_project/Campus_Query_AI_Assistant"
XAMPP_DIR="/opt/lampp"

if [ -d "$PROJECT_DIR" ]; then
    PROJECT_SIZE=$(du -sh "$PROJECT_DIR" 2>/dev/null | cut -f1)
    echo "   Project: $PROJECT_SIZE"
fi

if [ -d "$XAMPP_DIR" ]; then
    XAMPP_SIZE=$(du -sh "$XAMPP_DIR" 2>/dev/null | cut -f1)
    echo "   XAMPP: $XAMPP_SIZE"
fi

AVAILABLE=$(df -h /run/media/bcodz/Bcodz 2>/dev/null | tail -1 | awk '{print $4}')
echo "   Available: $AVAILABLE"
echo ""

# Recent Log Entries
echo "📝 Recent Log Activity"
echo "─────────────────────────────────────────"
LOG_FILE="$PROJECT_DIR/logs/backend.log"
if [ -f "$LOG_FILE" ]; then
    echo "Last 5 backend log entries:"
    tail -5 "$LOG_FILE" | while read line; do
        echo "   $line"
    done
else
    echo "   No backend log file found"
fi
echo ""

echo "========================================="
echo "Monitor complete. Run again with:"
echo "  watch -n 5 ./scripts/monitor_services.sh"
echo "========================================="
