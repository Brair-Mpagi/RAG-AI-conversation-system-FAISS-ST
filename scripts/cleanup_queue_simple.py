#!/usr/bin/env python3
"""
Simple Queue Cleanup - Remove Known Alias Patterns

Removes URLs from scrape_link_queue that match known WordPress alias patterns:
- URLs with ?p= parameter
- URLs with /YYYY/MM/DD?p= pattern
- URLs already in scraped_content
"""

import argparse
import pymysql
import pymysql.cursors
import re


def cleanup_queue_simple(db_config: dict, source_id: int = None, dry_run: bool = True):
    """
    Remove alias URLs from scrape_link_queue.
    
    Args:
        db_config: Database connection config
        source_id: Optional source_id to limit cleanup
        dry_run: If True, only report without making changes
    """
    conn = pymysql.connect(**db_config, cursorclass=pymysql.cursors.DictCursor)
    cursor = conn.cursor()
    
    try:
        # Build WHERE clause
        where_parts = ["status = 'pending'"]
        params = []
        
        if source_id:
            where_parts.append("source_id = %s")
            params.append(source_id)
        
        where_clause = " AND ".join(where_parts)
        
        # Count total pending
        cursor.execute(f"""
            SELECT COUNT(*) as count
            FROM scrape_link_queue
            WHERE {where_clause}
        """, params)
        total_pending = cursor.fetchone()['count']
        
        print(f"\n{'='*70}")
        print(f"Queue Cleanup - Source ID: {source_id if source_id else 'ALL'}")
        print(f"{'='*70}\n")
        print(f"Total pending URLs: {total_pending}\n")
        
        # 1. Find URLs with ?p= parameter (WordPress post ID fallback)
        query1 = f"""
            SELECT queue_id, page_url
            FROM scrape_link_queue
            WHERE {where_clause}
              AND page_url LIKE %s
            LIMIT 100
        """
        cursor.execute(query1, params + ['%?p=%'])
        wp_param_urls = cursor.fetchall()
        
        print(f"1. WordPress ?p= parameter URLs: {len(wp_param_urls)}")
        for row in wp_param_urls[:10]:
            print(f"   ✗ {row['page_url']}")
        if len(wp_param_urls) > 10:
            print(f"   ... and {len(wp_param_urls) - 10} more")
        
        # 2. Find URLs with date pattern + ?p= (malformed permalinks)
        query2 = f"""
            SELECT queue_id, page_url
            FROM scrape_link_queue
            WHERE {where_clause}
              AND page_url REGEXP '/[0-9]{{4}}/[0-9]{{1,2}}/[0-9]{{1,2}}\\\\?p='
            LIMIT 100
        """
        cursor.execute(query2, params)
        date_pattern_urls = cursor.fetchall()
        
        print(f"\n2. Date pattern URLs (/YYYY/MM/DD?p=): {len(date_pattern_urls)}")
        for row in date_pattern_urls[:10]:
            print(f"   ✗ {row['page_url']}")
        if len(date_pattern_urls) > 10:
            print(f"   ... and {len(date_pattern_urls) - 10} more")
        
        # 3. Find URLs already in scraped_content (skip due to collation issues)
        already_scraped = []
        print(f"\n3. URLs already scraped: Skipped (collation check)")
        
        # Count total to remove (with overlap handling)
        count_query = f"""
            SELECT COUNT(DISTINCT q.queue_id) as count
            FROM scrape_link_queue q
            WHERE {where_clause}
              AND (
                q.page_url LIKE %s
                OR q.page_url REGEXP '/[0-9]{{4}}/[0-9]{{1,2}}/[0-9]{{1,2}}\\\\?p='
              )
        """
        cursor.execute(count_query, params + ['%?p=%'])
        total_to_remove = cursor.fetchone()['count']
        
        print(f"\n{'='*70}")
        print(f"Summary:")
        print(f"  Total pending URLs:     {total_pending}")
        print(f"  URLs to remove:         {total_to_remove}")
        print(f"  URLs to keep:           {total_pending - total_to_remove}")
        print(f"  Reduction:              {(total_to_remove/total_pending*100):.1f}%")
        
        if not dry_run and total_to_remove > 0:
            # Remove the URLs
            delete_query = f"""
                DELETE FROM scrape_link_queue
                WHERE {where_clause}
                  AND (
                    page_url LIKE %s
                    OR page_url REGEXP '/[0-9]{{4}}/[0-9]{{1,2}}/[0-9]{{1,2}}\\\\?p='
                  )
            """
            cursor.execute(delete_query, params + ['%?p=%'])
            
            conn.commit()
            print(f"\n✓ Removed {cursor.rowcount} URLs from queue")
        
        if dry_run:
            print(f"\n⚠ DRY RUN - No changes made. Run with --apply to remove URLs.")
        else:
            print(f"\n✓ Queue cleanup complete!")
        
        print(f"{'='*70}\n")
        
    finally:
        cursor.close()
        conn.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Simple cleanup of alias URLs in scrape_link_queue')
    parser.add_argument('--db-host', default='localhost', help='Database host')
    parser.add_argument('--db-user', required=True, help='Database user')
    parser.add_argument('--db-password', required=True, help='Database password')
    parser.add_argument('--db-name', required=True, help='Database name')
    parser.add_argument('--source-id', type=int, help='Limit to specific source ID')
    parser.add_argument('--apply', action='store_true', help='Apply changes (default is dry-run)')
    
    args = parser.parse_args()
    
    db_config = {
        'host': args.db_host,
        'user': args.db_user,
        'password': args.db_password,
        'database': args.db_name,
    }
    
    cleanup_queue_simple(db_config, args.source_id, dry_run=not args.apply)
