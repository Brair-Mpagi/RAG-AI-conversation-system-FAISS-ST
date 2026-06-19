#!/bin/bash
# Campus Query AI Assistant - MySQL Database Backup Script

BACKUP_DIR="/run/media/bcodz/Bcodz/End_of_Sem_project/Campus_Query_AI_Assistant/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="campus_ai_db_${TIMESTAMP}.sql"
DB_NAME="campus_ai_db"
DB_USER="campus_ai_user"
DB_PASSWORD="root"
MYSQL_SOCKET="/opt/lampp/var/mysql/mysql.sock"

echo "========================================="
echo "Campus AI Database Backup"
echo "========================================="
echo ""

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Check if MySQL is running
if ! pgrep -x "mysqld" > /dev/null; then
    echo "❌ MySQL is not running!"
    echo "   Start with: sudo /opt/lampp/lampp startmysql"
    exit 1
fi

echo "📦 Backing up database: $DB_NAME"
echo "📁 Location: $BACKUP_DIR/$BACKUP_FILE"
echo ""

# Perform backup
/opt/lampp/bin/mysqldump \
    --socket="$MYSQL_SOCKET" \
    --skip-ssl \
    -u "$DB_USER" \
    -p"$DB_PASSWORD" \
    "$DB_NAME" > "$BACKUP_DIR/$BACKUP_FILE" 2>&1

if [ $? -eq 0 ]; then
    echo "✅ Database backup completed"
    
    # Compress backup
    echo "📦 Compressing backup..."
    gzip "$BACKUP_DIR/$BACKUP_FILE"
    
    if [ $? -eq 0 ]; then
        COMPRESSED_SIZE=$(du -h "$BACKUP_DIR/$BACKUP_FILE.gz" | cut -f1)
        echo "✅ Backup compressed: $COMPRESSED_SIZE"
        echo "📁 File: $BACKUP_FILE.gz"
    else
        echo "⚠️  Compression failed, but backup exists"
    fi

    # ── Vector store backup (ENT-05) ────────────────────────────────────
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    VECTOR_STORE_DIR="$SCRIPT_DIR/../backend/vector_store"
    VS_BACKUP_FILE="vector_store_${TIMESTAMP}.tar.gz"

    if [ -d "$VECTOR_STORE_DIR" ]; then
        echo ""
        echo "📦 Backing up vector store..."
        tar -czf "$BACKUP_DIR/$VS_BACKUP_FILE" -C "$(dirname "$VECTOR_STORE_DIR")" "$(basename "$VECTOR_STORE_DIR")" 2>/dev/null
        if [ $? -eq 0 ]; then
            VS_SIZE=$(du -h "$BACKUP_DIR/$VS_BACKUP_FILE" | cut -f1)
            echo "✅ Vector store backed up: $VS_SIZE (${VS_BACKUP_FILE})"
        else
            echo "⚠️  Vector store backup failed (directory may be empty)"
        fi
        # Clean old vector store backups (keep last 7 days)
        find "$BACKUP_DIR" -name "vector_store_*.tar.gz" -mtime +7 -delete
    else
        echo "ℹ️  Vector store directory not found — skipping"
    fi
    # ────────────────────────────────────────────────────────────────────

    # Keep only last 7 days of DB backups
    echo ""
    echo "🧹 Cleaning old backups (keeping last 7 days)..."
    find "$BACKUP_DIR" -name "campus_ai_db_*.sql.gz" -mtime +7 -delete
    
    # List recent backups
    echo ""
    echo "Recent backups:"
    ls -lh "$BACKUP_DIR"/campus_ai_db_*.sql.gz 2>/dev/null | tail -5
    
else
    echo "❌ Backup failed!"
    echo "   Check MySQL credentials and permissions"
    exit 1
fi

echo ""
echo "========================================="
echo "✅ Backup Complete!"
echo "========================================="

