#!/bin/bash
# Test connection between frontend and backend

echo "=================================="
echo "Connection Diagnostic Test"
echo "=================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check backend
echo -n "1. Testing backend on port 8000... "
if curl -s http://localhost:8000/ > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Backend is running${NC}"
else
    echo -e "${RED}✗ Backend is NOT running${NC}"
    echo "   Start with: cd backend && python main.py"
    exit 1
fi

# Check frontend
echo -n "2. Testing frontend on port 5173... "
if curl -s http://localhost:5173/ > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Frontend is running${NC}"
else
    echo -e "${RED}✗ Frontend is NOT running${NC}"
    echo "   Start with: cd interfaces/Ai_web/react && npm run dev"
    exit 1
fi

# Check API endpoint
echo -n "3. Testing chat API endpoint... "
RESPONSE=$(curl -s http://localhost:8000/api/v1/chat/test)
if echo "$RESPONSE" | grep -q "ok"; then
    echo -e "${GREEN}✓ API endpoint is reachable${NC}"
else
    echo -e "${RED}✗ API endpoint failed${NC}"
    exit 1
fi

# Test actual chat
echo -n "4. Testing chat functionality... "
CHAT_RESPONSE=$(curl -s -X POST http://localhost:8000/api/v1/chat \
    -H "Content-Type: application/json" \
    -d '{"prompt":"hello"}')
if echo "$CHAT_RESPONSE" | grep -q "response"; then
    echo -e "${GREEN}✓ Chat is working${NC}"
    echo ""
    echo "   Backend response: $(echo $CHAT_RESPONSE | jq -r '.response' 2>/dev/null || echo $CHAT_RESPONSE | head -c 80)..."
else
    echo -e "${RED}✗ Chat failed${NC}"
    echo "   Response: $CHAT_RESPONSE"
    exit 1
fi

echo ""
echo -e "${GREEN}=================================="
echo "All tests passed! ✓"
echo "==================================${NC}"
echo ""
echo "Frontend: http://localhost:5173"
echo "Backend:  http://localhost:8000"
echo "API Docs: http://localhost:8000/docs"
echo ""
echo -e "${YELLOW}Note: If frontend still can't connect:${NC}"
echo "1. Restart Vite: Ctrl+C in the Vite terminal, then 'npm run dev'"
echo "2. Clear browser cache (Ctrl+Shift+R)"
echo "3. Check browser console for detailed errors (F12)"
