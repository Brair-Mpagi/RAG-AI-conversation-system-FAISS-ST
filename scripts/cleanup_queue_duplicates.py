#!/usr/bin/env python3
"""
Cleanup Duplicate URLs in Missing Links Queue

Removes duplicate/alias URLs from scrape_link_queue table.
Keeps only canonical URLs based on:
1. Shortest URL
2. No query parameters
3. Clean path (no WordPress date patterns)
"""

import argparse
import pymysql
import pymysql.cursors
from collections import defaultdict
from urllib.parse import urlparse, parse_qs
import re


# WordPress alias patterns
ALIAS_PATTERNS = [
    re.compile(r'/\d{4}/\d{2}/\d{2}\?p=\d+'),
    re.compile(r'/\d{4}/\d{1,2}/\d{1,2}\?p=\d+'),
]


def normalize_url(url: str) -> str:
    """Normalize URL for comparison."""
    parsed = urlparse(url)
    scheme = parsed.scheme.lower()
    netloc = parsed.netloc.lower()
    path = parsed.path.rstrip('/') if parsed.path != '/' else '/'
    
    # Sort query params
    query_params = parse_qs(parsed.query)
    sorted_query = '&'.join(
        f"{k}={v[0]}" for k, v in sorted(query_params.items())
    ) if query_params else ''
    
    normalized = f"{scheme}://{netloc}{path}"
    if sorted_query:
        normalized += f"?{sorted_query}"
    
    return normalized


def is_alias_url(url: str) -> bool:
    """Check if URL matches alias patterns."""
    for pattern in ALIAS_PATTERNS:
        if pattern.search(url):
            return True
    
    parsed = urlparse(url)
    if 'p=' in parsed.query:
        return True
    
    return False


def url_preference_score(url: str) -> tuple:
    """
    Return tuple for sorting URLs by canonical preference.
    Lower score = more canonical.
    
    Returns: (is_alias, has_query, url_length, url)
    """
    is_alias = 1 if is_alias_url(url) else 0
    parsed = urlparse(url)
    has_query = 1 if parsed.query else 0
    return (is_alias, has_query, len(url), url)


def cleanup_queue_duplicates(db_config: dict, source_id: int = None, dry_run: bool = True):
    """
    Remove duplicate URLs from scrape_link_queue.
    
    Args:
        db_config: Database connection config
        source_id: Optional source_id to limit cleanup
        dry_run: If True, only report without making changes
    """
    conn = pymysql.connect(**db_config, cursorclass=pymysql.cursors.DictCursor)
    cursor = conn.cursor()
    
    try:
        # Get all pending URLs from queue
        query = """
            SELECT queue_id, source_id, page_url, discovered_from_url, crawl_depth, status
            FROM scrape_link_queue
            WHERE status = 'pending'
        """
        params = []
        
        if source_id:
            query += " AND source_id = %s"
            params.append(source_id)
        
        query += " ORDER BY source_id, discovered_at ASC"
        
        cursor.execute(query, params)
        all_urls = cursor.fetchall()
        
        print(f"\n{'='*70}")
        print(f"Found {len(all_urls)} pending URLs in queue")
        print(f"{'='*70}\n")
        
        # Group by normalized URL
        url_groups = defaultdict(list)
        for row in all_urls:
            normalized = normalize_url(row['page_url'])
            url_groups[normalized].append(row)
        
        # Find duplicates
        duplicates_to_remove = []
        canonical_kept = []
        
        for normalized_url, urls in url_groups.items():
            if len(urls) == 1:
                continue  # No duplicates
            
            # Sort to find canonical
            urls_sorted = sorted(urls, key=lambda u: url_preference_score(u['page_url']))
            
            canonical = urls_sorted[0]
            duplicates = urls_sorted[1:]
            
            canonical_kept.append(canonical)
            
            print(f"\nNormalized: {normalized_url}")
            print(f"  ✓ Keep:   {canonical['page_url']} (queue_id: {canonical['queue_id']})")
            
            for dup in duplicates:
                print(f"  ✗ Remove: {dup['page_url']} (queue_id: {dup['queue_id']})")
                duplicates_to_remove.append(dup['queue_id'])
        
        # Also check for URLs that are already in scraped_content
        print(f"\n{'='*70}")
        print(f"Checking for URLs already scraped (including aliases)...")
        print(f"{'='*70}\n")
        
        already_scraped = []
        alias_of_scraped = []
        
        for row in all_urls:
            # Check exact match
            cursor.execute("""
                SELECT scraped_id, page_url, is_canonical, canonical_url
                FROM scraped_content
                WHERE page_url = %s
                LIMIT 1
            """, (row['page_url'],))
            
            existing = cursor.fetchone()
            if existing:
                print(f"  ✗ Already scraped: {row['page_url']} (queue_id: {row['queue_id']})")
                already_scraped.append(row['queue_id'])
                continue
            
            # Check if this is an alias pattern and canonical exists
            if is_alias_url(row['page_url']):
                # Extract the base URL without query params
                parsed = urlparse(row['page_url'])
                
                # For ?p=ID patterns, we can't easily find the canonical
                # But we can check if any canonical URL exists with same domain
                # This is a heuristic - in production you'd want more sophisticated matching
                
                # For now, just mark obvious aliases
                if '?p=' in parsed.query:
                    # Check if there's a canonical page with similar path
                    base_path = parsed.path.rstrip('/')
                    if base_path == '' or base_path == '/':
                        # Root path with ?p= - likely homepage or specific page
                        # Check if canonical exists
                        cursor.execute("""
                            SELECT scraped_id, page_url
                            FROM scraped_content
                            WHERE source_id = %s
                              AND is_canonical = TRUE
                              AND (page_url LIKE %s OR page_url = %s)
                            LIMIT 1
                        """, (row['source_id'], f"%{parsed.query.split('=')[1]}%", f"{parsed.scheme}://{parsed.netloc}/"))
                        
                        canonical = cursor.fetchone()
                        if canonical:
                            print(f"  ⊘ Alias of scraped: {row['page_url']} → {canonical['page_url']} (queue_id: {row['queue_id']})")
                            alias_of_scraped.append(row['queue_id'])
        
        # Summary
        total_to_remove = len(duplicates_to_remove) + len(already_scraped) + len(alias_of_scraped)
        
        print(f"\n{'='*70}")
        print(f"Summary:")
        print(f"  Total pending URLs:        {len(all_urls)}")
        print(f"  Duplicate URLs to remove:  {len(duplicates_to_remove)}")
        print(f"  Already scraped to remove: {len(already_scraped)}")
        print(f"  Aliases of scraped:        {len(alias_of_scraped)}")
        print(f"  Total to remove:           {total_to_remove}")
        print(f"  URLs to keep:              {len(all_urls) - total_to_remove}")
        
        if not dry_run and total_to_remove > 0:
            # Remove duplicates
            all_to_remove = duplicates_to_remove + already_scraped + alias_of_scraped
            
            # Delete in batches of 100
            batch_size = 100
            for i in range(0, len(all_to_remove), batch_size):
                batch = all_to_remove[i:i+batch_size]
                placeholders = ','.join(['%s'] * len(batch))
                cursor.execute(f"""
                    DELETE FROM scrape_link_queue
                    WHERE queue_id IN ({placeholders})
                """, batch)
            
            conn.commit()
            print(f"\n✓ Removed {total_to_remove} duplicate/already-scraped URLs from queue")
        
        if dry_run:
            print(f"\n⚠ DRY RUN - No changes made. Run with --apply to remove duplicates.")
        else:
            print(f"\n✓ Queue cleanup complete!")
        
        print(f"{'='*70}\n")
        
    finally:
        cursor.close()
        conn.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Cleanup duplicate URLs in scrape_link_queue')
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
    
    cleanup_queue_duplicates(db_config, args.source_id, dry_run=not args.apply)
