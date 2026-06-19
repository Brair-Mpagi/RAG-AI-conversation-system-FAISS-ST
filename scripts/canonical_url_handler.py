#!/usr/bin/env python3
"""
Canonical URL Handler for Web Scraper
Implements proper URL canonicalization and content deduplication.

Purpose:
- Extract canonical URLs from <link rel="canonical">
- Detect duplicate content via content_hash
- Collapse alias URLs into canonical pages
- Filter malformed WordPress permalink patterns
- Prevent RAG embedding pollution from duplicates
"""

import re
from urllib.parse import urlparse, parse_qs
from typing import Optional, Dict, Set
from bs4 import BeautifulSoup


class CanonicalURLHandler:
    """Handles canonical URL detection and deduplication logic."""
    
    # WordPress permalink patterns that are likely aliases/malformed
    WORDPRESS_ALIAS_PATTERNS = [
        re.compile(r'/\d{4}/\d{2}/\d{2}\?p=\d+'),  # /2024/02/28?p=2876
        re.compile(r'/\d{4}/\d{2}/\d{1,2}\?p=\d+'),  # /2024/2/8?p=2876
        re.compile(r'/\d{4}/\d{1,2}/\d{1,2}\?p=\d+'),  # /2024/2/8?p=2876
    ]
    
    def __init__(self, conn):
        """
        Args:
            conn: Database connection for checking existing canonical URLs
        """
        self.conn = conn
        self._canonical_cache = {}  # Cache: content_hash -> canonical_scraped_id
    
    def extract_canonical_url(self, html: str, current_url: str) -> Optional[str]:
        """
        Extract canonical URL from HTML <link rel="canonical"> tag.
        
        Args:
            html: HTML content
            current_url: The URL that was fetched
            
        Returns:
            Canonical URL if found, otherwise None
        """
        try:
            soup = BeautifulSoup(html, 'lxml')
            canonical_tag = soup.find('link', rel='canonical')
            
            if canonical_tag and canonical_tag.get('href'):
                canonical_url = canonical_tag['href'].strip()
                
                # Make absolute if relative
                if canonical_url.startswith('/'):
                    parsed = urlparse(current_url)
                    canonical_url = f"{parsed.scheme}://{parsed.netloc}{canonical_url}"
                
                return self.normalize_url(canonical_url)
            
            return None
        except Exception:
            return None
    
    def normalize_url(self, url: str) -> str:
        """
        Normalize URL for comparison.
        - Remove fragments (#)
        - Remove trailing slashes
        - Sort query parameters
        - Lowercase scheme and domain
        """
        parsed = urlparse(url)
        
        # Normalize scheme and netloc
        scheme = parsed.scheme.lower()
        netloc = parsed.netloc.lower()
        
        # Normalize path
        path = parsed.path.rstrip('/') if parsed.path != '/' else '/'
        
        # Sort query parameters
        query_params = parse_qs(parsed.query)
        sorted_query = '&'.join(
            f"{k}={v[0]}" for k, v in sorted(query_params.items())
        ) if query_params else ''
        
        # Reconstruct without fragment
        normalized = f"{scheme}://{netloc}{path}"
        if sorted_query:
            normalized += f"?{sorted_query}"
        
        return normalized
    
    def is_likely_alias(self, url: str) -> bool:
        """
        Check if URL matches known alias patterns (WordPress malformed permalinks).
        
        Args:
            url: URL to check
            
        Returns:
            True if URL is likely an alias/malformed permalink
        """
        for pattern in self.WORDPRESS_ALIAS_PATTERNS:
            if pattern.search(url):
                return True
        
        # Check for ?p= parameter (WordPress post ID fallback)
        parsed = urlparse(url)
        if 'p=' in parsed.query:
            return True
        
        return False
    
    def find_canonical_by_hash(self, source_id: int, content_hash: str) -> Optional[Dict]:
        """
        Find existing canonical page with the same content hash.
        
        Args:
            source_id: Source ID
            content_hash: Content hash to search for
            
        Returns:
            Dict with scraped_id, page_url, canonical_url if found, else None
        """
        # Check cache first
        cache_key = f"{source_id}:{content_hash}"
        if cache_key in self._canonical_cache:
            scraped_id = self._canonical_cache[cache_key]
            if scraped_id:
                cursor = self.conn.cursor()
                cursor.execute("""
                    SELECT scraped_id, page_url, canonical_url
                    FROM scraped_content
                    WHERE scraped_id = %s
                """, (scraped_id,))
                return cursor.fetchone()
            return None
        
        # Query database
        cursor = self.conn.cursor()
        cursor.execute("""
            SELECT scraped_id, page_url, canonical_url
            FROM scraped_content
            WHERE source_id = %s 
              AND content_hash = %s
              AND is_canonical = TRUE
            ORDER BY scraped_at ASC
            LIMIT 1
        """, (source_id, content_hash))
        
        result = cursor.fetchone()
        
        # Cache result
        if result:
            self._canonical_cache[cache_key] = result['scraped_id']
        else:
            self._canonical_cache[cache_key] = None
        
        return result
    
    def should_skip_as_duplicate(
        self, 
        source_id: int, 
        url: str, 
        content_hash: str,
        canonical_url: Optional[str]
    ) -> tuple[bool, Optional[int]]:
        """
        Determine if this URL should be skipped as a duplicate.
        
        Args:
            source_id: Source ID
            url: Current URL being scraped
            content_hash: Hash of the content
            canonical_url: Extracted canonical URL (if any)
            
        Returns:
            (should_skip, canonical_page_id)
            - should_skip: True if this is a duplicate that should be marked as alias
            - canonical_page_id: ID of the canonical page (if duplicate)
        """
        # Find existing page with same content hash
        canonical_page = self.find_canonical_by_hash(source_id, content_hash)
        
        if not canonical_page:
            # No duplicate found - this is a new unique page
            return (False, None)
        
        canonical_page_url = canonical_page['page_url']
        canonical_page_id = canonical_page['scraped_id']
        
        # If current URL matches the canonical page URL, it's not a duplicate
        if self.normalize_url(url) == self.normalize_url(canonical_page_url):
            return (False, None)
        
        # If canonical URL is provided and matches existing canonical, this is an alias
        if canonical_url and self.normalize_url(canonical_url) == self.normalize_url(canonical_page_url):
            return (True, canonical_page_id)
        
        # If current URL is a known alias pattern, mark as duplicate
        if self.is_likely_alias(url):
            return (True, canonical_page_id)
        
        # Content hash matches but URLs differ - likely duplicate
        # Prefer the shorter, cleaner URL as canonical
        if len(url) > len(canonical_page_url) or '?' in url:
            return (True, canonical_page_id)
        
        return (False, None)
    
    def add_alias_to_canonical(self, canonical_page_id: int, alias_url: str):
        """
        Add an alias URL to the canonical page's alias list.
        
        Args:
            canonical_page_id: ID of the canonical page
            alias_url: Alias URL to add
        """
        cursor = self.conn.cursor()
        
        # Get current aliases
        cursor.execute("""
            SELECT url_aliases FROM scraped_content WHERE scraped_id = %s
        """, (canonical_page_id,))
        
        result = cursor.fetchone()
        if not result:
            return
        
        import json
        aliases = json.loads(result['url_aliases']) if result['url_aliases'] else []
        
        # Add new alias if not already present
        normalized_alias = self.normalize_url(alias_url)
        if normalized_alias not in aliases:
            aliases.append(normalized_alias)
            
            cursor.execute("""
                UPDATE scraped_content
                SET url_aliases = %s
                WHERE scraped_id = %s
            """, (json.dumps(aliases), canonical_page_id))
            
            self.conn.commit()
