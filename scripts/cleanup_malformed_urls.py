#!/usr/bin/env python3
"""
Cleanup script to remove malformed URLs from the Missing Links Queue.

Removes URLs with recursive encoding patterns like:
- https://mmu.ac.ug/?p=6505%2F%3Fp%3D7868%2F%3Fp%3D2876
- https://mmu.ac.ug/2023/12/28?p=7868%2F%3Fp%3D2876
- https://mmu.ac.ug/2023/11/28?p=2876%2F%3Fp%3D7868

These are WordPress pagination artifacts that create infinite loops.
"""

import argparse
import re
import pymysql
import pymysql.cursors

def cleanup_malformed_urls(db_config, dry_run=True):
    """Remove malformed URLs from scrape_link_queue"""
    
    conn = pymysql.connect(
        **db_config,
        cursorclass=pymysql.cursors.DictCursor
    )
    
    try {
        cursor = conn.cursor()
        
        # Find malformed URLs with comprehensive patterns
        print("Scanning for malformed URLs...")
        cursor.execute("""
            SELECT queue_id, page_url, status
            FROM scrape_link_queue
            WHERE 
                -- Recursive encoding patterns
                page_url LIKE '%?p=%?p=%'
                OR page_url LIKE '%?p=%/%?p=%'
                OR page_url LIKE '%2F%3Fp%3D%'
                -- Duplicate path segments (e.g., /news/6423/news/6423)
                OR page_url REGEXP '/([^/]+)/([^/?]+)/\\1/\\2'
                -- WordPress ?p= on non-root paths (e.g., /news/123?p=456)
                OR page_url REGEXP '/[^/]+/[^/?]+\\?p=[0-9]+'
                -- Date patterns with ?p= (e.g., /2023/11/28?p=123)
                OR page_url REGEXP '/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\\?p=[0-9]+'
            ORDER BY discovered_at DESC
        """)
        
        malformed = cursor.fetchall()
        
        if not malformed:
            print("✓ No malformed URLs found!")
            return
        
        print(f"\nFound {len(malformed)} malformed URLs:")
        
        # Group by pattern
        patterns = {
            'recursive_encoded': [],  # %2F%3Fp%3D
            'multiple_params': [],    # ?p=123?p=456
            'slash_params': [],       # ?p=123/?p=456
            'duplicate_paths': [],    # /news/6423/news/6423
            'wp_param_on_slug': [],   # /news/123?p=456
            'date_with_param': []     # /2023/11/28?p=123
        }
        
        for item in malformed:
            url = item['page_url']
            if '%2F%3Fp%3D' in url:
                patterns['recursive_encoded'].append(item)
            elif url.count('?p=') > 1 and '/' not in url.split('?p=')[1]:
                patterns['multiple_params'].append(item)
            elif '/?p=' in url and url.count('?p=') > 1:
                patterns['slash_params'].append(item)
            elif re.search(r'/([^/]+)/([^/?]+)/\1/\2', url):
                patterns['duplicate_paths'].append(item)
            elif re.search(r'/[^/]+/[^/?]+\?p=[0-9]+', url):
                patterns['wp_param_on_slug'].append(item)
            elif re.search(r'/[0-9]{4}/[0-9]{2}(/[0-9]{2})?\?p=[0-9]+', url):
                patterns['date_with_param'].append(item)
        
        print(f"  - Recursive encoded (%2F%3Fp%3D): {len(patterns['recursive_encoded'])}")
        print(f"  - Multiple ?p= params: {len(patterns['multiple_params'])}")
        print(f"  - Slash in params (?p=123/?p=456): {len(patterns['slash_params'])}")
        print(f"  - Duplicate paths (/news/123/news/123): {len(patterns['duplicate_paths'])}")
        print(f"  - WP param on slug (/news/123?p=456): {len(patterns['wp_param_on_slug'])}")
        print(f"  - Date with param (/2023/11?p=123): {len(patterns['date_with_param'])}")
        
        # Show examples
        print("\nExamples:")
        for pattern_name, items in patterns.items():
            if items:
                print(f"\n  {pattern_name}:")
                for item in items[:3]:
                    print(f"    - {item['page_url'][:80]}...")
        
        if dry_run:
            print(f"\n⚠ DRY RUN MODE - No changes made")
            print(f"Run with --apply to actually delete these {len(malformed)} URLs")
        else:
            # Delete malformed URLs
            queue_ids = [item['queue_id'] for item in malformed]
            
            print(f"\n🗑 Deleting {len(queue_ids)} malformed URLs...")
            
            # Delete in batches of 1000
            batch_size = 1000
            deleted = 0
            
            for i in range(0, len(queue_ids), batch_size):
                batch = queue_ids[i:i+batch_size]
                placeholders = ','.join(['%s'] * len(batch))
                cursor.execute(f"""
                    DELETE FROM scrape_link_queue
                    WHERE queue_id IN ({placeholders})
                """, batch)
                conn.commit()
                deleted += len(batch)
                print(f"  Deleted {deleted}/{len(queue_ids)}...")
            
            print(f"\n✓ Successfully deleted {deleted} malformed URLs")
            
            # Show remaining counts
            cursor.execute("SELECT COUNT(*) as total FROM scrape_link_queue")
            total = cursor.fetchone()['total']
            
            cursor.execute("SELECT COUNT(*) as pending FROM scrape_link_queue WHERE status='pending'")
            pending = cursor.fetchone()['pending']
            
            print(f"\nRemaining in queue:")
            print(f"  Total: {total}")
            print(f"  Pending: {pending}")
        
        cursor.close()
        
    finally:
        conn.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Cleanup malformed URLs from Missing Links Queue')
    parser.add_argument('--db-host', default='localhost', help='Database host')
    parser.add_argument('--db-user', required=True, help='Database user')
    parser.add_argument('--db-password', required=True, help='Database password')
    parser.add_argument('--db-name', required=True, help='Database name')
    parser.add_argument('--apply', action='store_true', help='Actually delete (default is dry-run)')
    
    args = parser.parse_args()
    
    db_config = {
        'host': args.db_host,
        'user': args.db_user,
        'password': args.db_password,
        'database': args.db_name,
    }
    
    cleanup_malformed_urls(db_config, dry_run=not args.apply)
