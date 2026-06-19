#!/usr/bin/env python3
"""
Cleanup Existing Duplicate Content
Identifies and consolidates duplicate pages based on content_hash.

This script:
1. Finds all pages with duplicate content_hash
2. Identifies the canonical version (shortest URL, no query params)
3. Marks others as aliases
4. Updates canonical_page_id references
5. Adds aliases to canonical page's url_aliases JSON
"""

import argparse
import json
import pymysql
import pymysql.cursors
from collections import defaultdict
from urllib.parse import urlparse, parse_qs


def normalize_url_for_comparison(url: str) -> tuple:
    """
    Return a tuple for comparing URLs to determine canonical preference.
    Lower values = more canonical.
    
    Returns: (has_query_params, url_length, url)
    """
    parsed = urlparse(url)
    has_query = 1 if parsed.query else 0
    return (has_query, len(url), url)


def cleanup_duplicates(db_config: dict, source_id: int = None, dry_run: bool = True):
    """
    Find and consolidate duplicate content.
    
    Args:
        db_config: Database connection config
        source_id: Optional source_id to limit cleanup to specific source
        dry_run: If True, only report what would be done without making changes
    """
    conn = pymysql.connect(**db_config, cursorclass=pymysql.cursors.DictCursor)
    cursor = conn.cursor()
    
    try:
        # Find all content_hash values that have duplicates
        query = """
            SELECT content_hash, COUNT(*) as count
            FROM scraped_content
            WHERE content_hash IS NOT NULL
        """
        params = []
        
        if source_id:
            query += " AND source_id = %s"
            params.append(source_id)
        
        query += """
            GROUP BY content_hash
            HAVING count > 1
            ORDER BY count DESC
        """
        
        cursor.execute(query, params)
        duplicate_hashes = cursor.fetchall()
        
        print(f"\n{'='*70}")
        print(f"Found {len(duplicate_hashes)} content_hash values with duplicates")
        print(f"{'='*70}\n")
        
        total_duplicates = 0
        total_canonical = 0
        
        for row in duplicate_hashes:
            content_hash = row['content_hash']
            count = row['count']
            
            # Get all pages with this content_hash
            query = """
                SELECT scraped_id, source_id, page_url, page_title, canonical_url, is_canonical
                FROM scraped_content
                WHERE content_hash = %s
            """
            params = [content_hash]
            
            if source_id:
                query += " AND source_id = %s"
                params.append(source_id)
            
            query += " ORDER BY scraped_at ASC"
            
            cursor.execute(query, params)
            pages = cursor.fetchall()
            
            if len(pages) < 2:
                continue
            
            # Sort to find canonical (prefer: no query params, shorter URL, oldest)
            pages_sorted = sorted(pages, key=lambda p: normalize_url_for_comparison(p['page_url']))
            
            canonical_page = pages_sorted[0]
            alias_pages = pages_sorted[1:]
            
            print(f"\nContent Hash: {content_hash[:12]}... ({count} duplicates)")
            print(f"  Title: {canonical_page['page_title'][:60]}")
            print(f"  ✓ Canonical: {canonical_page['page_url']}")
            
            alias_urls = []
            for alias in alias_pages:
                print(f"  ⊘ Alias:     {alias['page_url']}")
                alias_urls.append(alias['page_url'])
            
            if not dry_run:
                # Update canonical page
                cursor.execute("""
                    UPDATE scraped_content
                    SET is_canonical = TRUE,
                        canonical_url = %s,
                        canonical_page_id = NULL,
                        url_aliases = %s
                    WHERE scraped_id = %s
                """, (
                    canonical_page['page_url'],
                    json.dumps(alias_urls),
                    canonical_page['scraped_id']
                ))
                
                # Update alias pages
                for alias in alias_pages:
                    cursor.execute("""
                        UPDATE scraped_content
                        SET is_canonical = FALSE,
                            canonical_url = %s,
                            canonical_page_id = %s,
                            status = 'duplicate'
                        WHERE scraped_id = %s
                    """, (
                        canonical_page['page_url'],
                        canonical_page['scraped_id'],
                        alias['scraped_id']
                    ))
                
                conn.commit()
                print(f"  → Updated in database")
            
            total_canonical += 1
            total_duplicates += len(alias_pages)
        
        print(f"\n{'='*70}")
        print(f"Summary:")
        print(f"  Canonical pages: {total_canonical}")
        print(f"  Alias pages:     {total_duplicates}")
        print(f"  Total cleaned:   {total_canonical + total_duplicates}")
        
        if dry_run:
            print(f"\n⚠ DRY RUN - No changes made. Run with --apply to make changes.")
        else:
            print(f"\n✓ Cleanup complete!")
        print(f"{'='*70}\n")
        
    finally:
        cursor.close()
        conn.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Cleanup duplicate scraped content')
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
    
    cleanup_duplicates(db_config, args.source_id, dry_run=not args.apply)
