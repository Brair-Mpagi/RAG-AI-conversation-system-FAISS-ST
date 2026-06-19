#!/usr/bin/env python3
"""
Web Scraper for Campus AI Chatbot System
Scrapes content from configured sources and stores in database.

Features:
- Incremental scraping (insert new, update changed, skip unchanged)
- Smart main-content extraction (excludes nav, footer, sidebar, breadcrumbs)
- URL normalization (fragments, trailing slashes, query dedup)
- Content hashing for change detection (SHA-256)
- Version history tracking in scraped_content_history
- Metadata extraction (author, publish date, category)
- Graceful error handling (404s, non-HTML, timeouts)
- Resume-safe: pre-loads existing URLs from database
"""

import argparse
import hashlib
import json
import re
import socket
import sys
import time
from datetime import datetime
from urllib.parse import urljoin, urlparse, urlunparse, parse_qs, urlencode
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from bs4 import BeautifulSoup, NavigableString
import pymysql
import pymysql.cursors
from typing import List, Dict, Set, Optional, Tuple


# File extensions to skip (non-HTML resources)
SKIP_EXTENSIONS = {
    '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.ico', '.bmp', '.tiff',
    '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
    '.zip', '.rar', '.gz', '.tar', '.7z',
    '.mp3', '.mp4', '.avi', '.mov', '.wmv', '.flv', '.wav', '.ogg',
    '.css', '.js', '.json', '.xml', '.rss',
    '.exe', '.msi', '.dmg', '.apk',
    '.woff', '.woff2', '.ttf', '.eot',
    '.ics', '.ical',  # Calendar files
}

# Elements to remove before content extraction
NOISE_SELECTORS = [
    'nav', 'footer', 'header',
    'aside',
    '[role="navigation"]', '[role="banner"]', '[role="contentinfo"]',
    '.breadcrumb', '.breadcrumbs', '#breadcrumbs',
    '.sidebar', '#sidebar', '.side-bar',
    '.menu', '.nav-menu', '.main-menu', '.navigation',
    '.footer', '#footer', '.site-footer',
    '.header', '#header', '.site-header',
    '.cookie-banner', '.cookie-notice',
    '.social-share', '.social-links',
    '.pagination',
    '.comments', '#comments',
    '.advertisement', '.ad-banner',
    'script', 'style', 'noscript', 'iframe',
    'form',
    # University/campus site specific
    '.mega-menu', '.dropdown-menu', '.sub-menu',
    '.top-bar', '.topbar', '.utility-nav', '.utility-bar',
    '#navigation', '#nav', '#top-nav', '#main-nav',
    '.campus-nav', '.site-nav', '.global-nav',
    '.mobile-menu', '.mobile-nav', '#mobile-menu',
    '.search-form', '.search-bar', '#search',
    '.skip-link', '.skip-nav',
    '.toolbar', '#toolbar',
    '.quick-links', '.footer-links', '.footer-nav',
    '.back-to-top', '.scroll-top',
    '.share-buttons', '.print-button',
    '.related-links', '.related-pages',
    '.alert-bar', '.announcement-bar',
    '.login-form', '.user-menu',
    'ul.nav', 'ol.breadcrumb',
]

# Selectors to try for finding main content (in priority order)
MAIN_CONTENT_SELECTORS = [
    'main',
    'article',
    '[role="main"]',
    '#main-content', '#maincontent', '#main_content',
    '.main-content', '.maincontent', '.main_content',
    '#content', '.content',
    '.page-content', '.page_content',
    '.entry-content', '.post-content',
    '#body-content', '.body-content',
]


class WebScraper:
    def __init__(self, db_config: Dict):
        """Initialize web scraper with database configuration"""
        self.db_config = db_config
        self.conn = None
        self.visited_urls: Set[str] = set()
        self.blocked_urls: Set[str] = set()  # URLs that should never be scraped

        # Counters
        self.new_count = 0
        self.updated_count = 0
        self.unchanged_count = 0
        self.skipped_count = 0
        self.failed_count = 0

        # Set up requests session with retries and connection pooling
        self.session = requests.Session()
        retry_strategy = Retry(
            total=3,
            backoff_factor=1,
            status_forcelist=[429, 500, 502, 503, 504],
        )
        adapter = HTTPAdapter(
            max_retries=retry_strategy,
            pool_connections=10,  # Limit connection pool
            pool_maxsize=20,      # Max connections in pool
            pool_block=False      # Don't block when pool is full
        )
        self.session.mount("http://", adapter)
        self.session.mount("https://", adapter)
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Accept-Encoding': 'gzip, deflate',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
        })
        self.request_timeout = 15  # Reduced from 30 to prevent long hangs
        self.force_refresh = False

    # ------------------------------------------------------------------
    # Database helpers
    # ------------------------------------------------------------------

    def connect_db(self):
        """Connect to MySQL database"""
        try:
            self.conn = pymysql.connect(
                **self.db_config,
                cursorclass=pymysql.cursors.DictCursor,
                connect_timeout=10
            )
            print(f"✓ Connected to database: {self.db_config['database']}")
        except pymysql.Error as e:
            print(f"✗ Database connection failed: {e}")
            sys.exit(1)

    def close_db(self):
        """Close database connection"""
        if self.conn and self.conn.open:
            self.conn.close()

    def refresh_db_connection(self):
        """Refresh database connection to prevent timeouts"""
        try:
            if self.conn and self.conn.open:
                self.conn.ping(reconnect=True)
            else:
                self.connect_db()
        except Exception as e:
            print(f"  ⚠ DB connection lost, reconnecting: {e}")
            self.connect_db()
            print("✓ Database connection closed")

    def preload_visited_urls(self, source_id: int):
        """Load already-scraped URLs and blocked URLs from database"""
        cursor = self.conn.cursor()
        
        # Load blocked URLs - these should NEVER be scraped
        cursor.execute(
            "SELECT page_url FROM blocked_urls WHERE source_id = %s",
            (source_id,)
        )
        blocked_rows = cursor.fetchall()
        for row in blocked_rows:
            self.blocked_urls.add(row['page_url'])
        if self.blocked_urls:
            print(f"✓ Pre-loaded {len(self.blocked_urls)} blocked URLs")
        
        # Load already-scraped URLs
        cursor.execute(
            "SELECT page_url FROM scraped_content WHERE source_id = %s",
            (source_id,)
        )
        rows = cursor.fetchall()
        cursor.close()
        for row in rows:
            self.visited_urls.add(row['page_url'])
        if self.visited_urls:
            print(f"✓ Pre-loaded {len(self.visited_urls)} already-scraped URLs from database")

    # ------------------------------------------------------------------
    # URL normalization
    # ------------------------------------------------------------------

    @staticmethod
    def normalize_url(url: str) -> str:
        """Normalize URL to prevent duplicates from minor differences.

        - Strip fragments (#section)
        - Remove trailing slash (except root path)
        - Sort and deduplicate query parameters
        - Lowercase scheme and host
        """
        parsed = urlparse(url)

        # Lowercase scheme and host
        scheme = parsed.scheme.lower()
        netloc = parsed.netloc.lower()

        # Normalize path: remove trailing slash (but keep '/')
        path = parsed.path
        if path != '/' and path.endswith('/'):
            path = path.rstrip('/')

        # Sort and deduplicate query parameters
        query_params = parse_qs(parsed.query, keep_blank_values=True)
        sorted_query = urlencode(
            sorted(query_params.items()),
            doseq=True
        ) if query_params else ''

        # Rebuild without fragment
        normalized = urlunparse((scheme, netloc, path, parsed.params, sorted_query, ''))
        return normalized

    @staticmethod
    def should_skip_url(url: str) -> bool:
        """Check if URL points to a non-HTML resource or calendar download link."""
        parsed = urlparse(url)
        path_lower = parsed.path.lower()
        
        # Skip file extensions
        for ext in SKIP_EXTENSIONS:
            if path_lower.endswith(ext):
                return True
        
        # Skip calendar download links (ical, outlook-ical parameters)
        query = parsed.query or ''
        if 'ical=' in query or 'outlook-ical=' in query:
            return True
        
        return False

    # ------------------------------------------------------------------
    # Content extraction
    # ------------------------------------------------------------------

    @staticmethod
    def compute_hash(content: str) -> str:
        """Compute SHA-256 hash of content for change detection."""
        return hashlib.sha256(content.encode('utf-8')).hexdigest()

    def extract_main_content(self, soup: BeautifulSoup, config: Dict) -> Dict:
        """Extract only the main content of a page, excluding noise.

        Strategy:
        1. Remove all noise elements (nav, footer, sidebar, breadcrumbs, etc.)
        2. Try to locate the main content area using smart selectors
        3. Fall back to configured selector or <body>
        4. Preserve structure: headings, paragraphs, lists
        5. Extract metadata (author, publish date, category)
        """
        selectors = config.get('selectors', {})

        # Step 1: Extract title BEFORE removing elements
        title_selector = selectors.get('title', 'h1, title')
        title_elem = soup.select_one(title_selector)
        title = title_elem.get_text(strip=True) if title_elem else ''

        # Step 2: Extract metadata BEFORE removing elements
        metadata = self._extract_metadata(soup)

        # Step 3: Remove all noise elements
        noise_selector = selectors.get('exclude', ', '.join(NOISE_SELECTORS))
        for selector in noise_selector.split(','):
            selector = selector.strip()
            if selector:
                try:
                    for elem in soup.select(selector):
                        elem.decompose()
                except Exception:
                    pass  # Skip invalid selectors

        # Step 4: Find main content area
        content_elem = None

        # Try configured selector first
        custom_selector = selectors.get('content', '')
        if custom_selector and custom_selector.lower() != 'body':
            content_elem = soup.select_one(custom_selector)

        # Try smart main-content selectors
        if not content_elem:
            for sel in MAIN_CONTENT_SELECTORS:
                content_elem = soup.select_one(sel)
                if content_elem:
                    break

        # Fall back to body
        if not content_elem:
            content_elem = soup.find('body')

        if not content_elem:
            return {'title': title, 'content': '', 'description': '',
                    'meta_author': None, 'meta_publish_date': None, 'meta_category': None}

        # Step 5: Extract structured text preserving headings and lists
        content = self._extract_structured_text(content_elem)

        # Step 6: Extract description
        meta_desc = soup.find('meta', attrs={'name': 'description'})
        description = meta_desc.get('content', '') if meta_desc else ''

        return {
            'title': title,
            'content': content,
            'description': description,
            'meta_author': metadata.get('author'),
            'meta_publish_date': metadata.get('publish_date'),
            'meta_category': metadata.get('category'),
        }

    @staticmethod
    def _extract_metadata(soup: BeautifulSoup) -> Dict:
        """Extract metadata from page: author, publish date, category."""
        metadata = {}

        # Author
        for selector in [
            ('meta', {'name': 'author'}),
            ('meta', {'property': 'article:author'}),
            ('meta', {'name': 'dc.creator'}),
        ]:
            tag = soup.find(selector[0], attrs=selector[1])
            if tag and tag.get('content'):
                metadata['author'] = tag['content'].strip()
                break

        # Publish date
        for selector in [
            ('meta', {'property': 'article:published_time'}),
            ('meta', {'name': 'date'}),
            ('meta', {'name': 'dc.date'}),
            ('meta', {'property': 'og:updated_time'}),
        ]:
            tag = soup.find(selector[0], attrs=selector[1])
            if tag and tag.get('content'):
                metadata['publish_date'] = tag['content'].strip()
                break

        # Try <time> element if no meta date found
        if 'publish_date' not in metadata:
            time_elem = soup.find('time', attrs={'datetime': True})
            if time_elem:
                metadata['publish_date'] = time_elem['datetime'].strip()

        # Category
        for selector in [
            ('meta', {'property': 'article:section'}),
            ('meta', {'name': 'category'}),
            ('meta', {'name': 'dc.subject'}),
        ]:
            tag = soup.find(selector[0], attrs=selector[1])
            if tag and tag.get('content'):
                metadata['category'] = tag['content'].strip()
                break

        return metadata

    @staticmethod
    def _extract_structured_text(element) -> str:
        """Extract text from an element preserving structure.

        - Headings are prefixed with ## markers
        - List items are prefixed with bullet points
        - Paragraphs are separated by blank lines
        - Whitespace is normalized
        """
        lines = []

        for child in element.descendants:
            if isinstance(child, NavigableString):
                continue

            tag_name = child.name
            if tag_name is None:
                continue

            # Only process leaf-level block elements to avoid duplication
            if tag_name in ('h1', 'h2', 'h3', 'h4', 'h5', 'h6'):
                text = child.get_text(strip=True)
                if text:
                    level = int(tag_name[1])
                    prefix = '#' * level
                    lines.append(f"\n{prefix} {text}\n")

            elif tag_name == 'p':
                text = child.get_text(strip=True)
                if text:
                    lines.append(f"\n{text}\n")

            elif tag_name == 'li':
                text = child.get_text(strip=True)
                if text:
                    lines.append(f"  • {text}")

            elif tag_name in ('blockquote',):
                text = child.get_text(strip=True)
                if text:
                    lines.append(f"\n> {text}\n")

            elif tag_name == 'br':
                lines.append("")

        # Join and normalize
        raw_text = '\n'.join(lines)

        # Collapse multiple blank lines to at most two
        raw_text = re.sub(r'\n{3,}', '\n\n', raw_text)

        # Strip leading/trailing whitespace
        raw_text = raw_text.strip()

        return raw_text

    @staticmethod
    def _clean_extracted_text(text: str) -> str:
        """Post-extraction cleaning to remove navigation clutter and duplicates.

        - Remove lines that are just nav items (single short words like 'Home', 'About Us')
        - Remove lines that look like link lists
        - Remove duplicate paragraphs
        - Remove very short lines that are just labels
        """
        # Common navigation items to filter out
        NAV_WORDS = {
            'home', 'about', 'about us', 'contact', 'contact us',
            'services', 'governance', 'administrative units',
            'news', 'events', 'gallery', 'media',
            'login', 'sign in', 'register', 'sign up',
            'search', 'menu', 'close', 'back to top',
            'skip to content', 'skip to main content', 'skip navigation',
            'privacy policy', 'terms', 'terms of use', 'cookie policy',
            'sitemap', 'site map', 'accessibility',
            'follow us', 'connect with us', 'social media',
            'share', 'print', 'email', 'tweet', 'share this',
            'facebook', 'twitter', 'instagram', 'linkedin', 'youtube',
            'read more', 'learn more', 'view more', 'see more', 'more info',
            'click here', 'previous', 'next', 'back',
            'all rights reserved', 'copyright',
        }

        lines = text.split('\n')
        cleaned = []
        seen_paragraphs = set()

        for line in lines:
            stripped = line.strip()

            # Skip empty lines (but preserve paragraph spacing)
            if not stripped:
                if cleaned and cleaned[-1] != '':
                    cleaned.append('')
                continue

            # Skip heading markers alone
            if stripped.startswith('#') and len(stripped.lstrip('#').strip()) == 0:
                continue

            # Get the text content (without markdown heading markers)
            text_content = stripped.lstrip('#').strip().lstrip('•').strip()
            text_lower = text_content.lower()

            # Skip lines that are just navigation words
            if text_lower in NAV_WORDS:
                continue

            # Skip very short lines (< 3 chars) that are just labels
            if len(text_content) < 3 and not stripped.startswith('#'):
                continue

            # Skip lines that look like phone/fax labels
            if re.match(r'^(tel|fax|phone|email)\s*[:.]?\s*$', text_lower):
                continue

            # Skip bullet points that are just single short nav words
            if stripped.startswith('•') and text_lower in NAV_WORDS:
                continue

            # Deduplicate paragraphs (non-heading lines)
            if not stripped.startswith('#') and not stripped.startswith('•'):
                para_key = re.sub(r'\s+', ' ', text_lower)
                if para_key in seen_paragraphs:
                    continue
                seen_paragraphs.add(para_key)

            cleaned.append(stripped)

        result = '\n'.join(cleaned)
        # Collapse multiple blank lines
        result = re.sub(r'\n{3,}', '\n\n', result)
        return result.strip()

    # ------------------------------------------------------------------
    # Database save with incremental logic
    # ------------------------------------------------------------------

    def save_scraped_content(self, source_id: int, url: str, data: Dict,
                              parent_url: str = None, crawl_depth: int = 0) -> str:
        """Save scraped content incrementally.

        Returns: 'new', 'updated', 'unchanged', or 'failed'
        """
        cursor = self.conn.cursor()
        content_hash = self.compute_hash(data['content'])

        try:
            # Check if URL already exists for this source
            cursor.execute(
                "SELECT scraped_id, content_hash, page_title, cleaned_content FROM scraped_content "
                "WHERE source_id = %s AND page_url = %s",
                (source_id, url)
            )
            existing = cursor.fetchone()

            if existing:
                # URL exists — check if content changed
                if existing['content_hash'] == content_hash:
                    # Content unchanged — just update scraped_at timestamp
                    cursor.execute(
                        "UPDATE scraped_content SET scraped_at = NOW() WHERE scraped_id = %s",
                        (existing['scraped_id'],)
                    )
                    self.conn.commit()
                    self.unchanged_count += 1
                    return 'unchanged'
                else:
                    # Content changed — save old version to history, then update
                    cursor.execute("""
                        INSERT INTO scraped_content_history 
                        (scraped_id, page_url, page_title, cleaned_content, content_hash)
                        VALUES (%s, %s, %s, %s, %s)
                    """, (
                        existing['scraped_id'],
                        url,
                        existing['page_title'],
                        existing['cleaned_content'],
                        existing['content_hash']
                    ))

                    cursor.execute("""
                        UPDATE scraped_content
                        SET page_title = %s, cleaned_content = %s, content_hash = %s,
                            meta_author = %s, meta_publish_date = %s, meta_category = %s,
                            status = 'updated', scraped_at = NOW()
                        WHERE scraped_id = %s
                    """, (
                        data['title'][:500] if data['title'] else 'Untitled',
                        data['content'],
                        content_hash,
                        data.get('meta_author'),
                        data.get('meta_publish_date'),
                        data.get('meta_category'),
                        existing['scraped_id']
                    ))
                    self.conn.commit()
                    self.updated_count += 1
                    return 'updated'
            else:
                # New URL — insert
                cursor.execute("""
                    INSERT INTO scraped_content
                    (source_id, page_url, page_title, cleaned_content, content_hash,
                     meta_author, meta_publish_date, meta_category, status,
                     parent_url, crawl_depth)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 'new', %s, %s)
                """, (
                    source_id,
                    url,
                    data['title'][:500] if data['title'] else 'Untitled',
                    data['content'],
                    content_hash,
                    data.get('meta_author'),
                    data.get('meta_publish_date'),
                    data.get('meta_category'),
                    parent_url,
                    crawl_depth,
                ))
                self.conn.commit()
                self.new_count += 1
                return 'new'

        except pymysql.IntegrityError:
            # Safety net: duplicate key — try update instead
            self.conn.rollback()
            try:
                cursor.execute("""
                    UPDATE scraped_content
                    SET page_title = %s, cleaned_content = %s, content_hash = %s,
                        meta_author = %s, meta_publish_date = %s, meta_category = %s,
                        status = 'updated', scraped_at = NOW()
                    WHERE source_id = %s AND page_url = %s
                """, (
                    data['title'][:500] if data['title'] else 'Untitled',
                    data['content'],
                    content_hash,
                    data.get('meta_author'),
                    data.get('meta_publish_date'),
                    data.get('meta_category'),
                    source_id,
                    url,
                ))
                self.conn.commit()
                self.updated_count += 1
                return 'updated'
            except pymysql.Error as e2:
                print(f"    ✗ Fallback update also failed: {e2}")
                self.conn.rollback()
                self.failed_count += 1
                return 'failed'

        except pymysql.Error as e:
            print(f"    ✗ Database error: {e}")
            self.conn.rollback()
            self.failed_count += 1
            return 'failed'
        finally:
            cursor.close()

    # ------------------------------------------------------------------
    # Configuration
    # ------------------------------------------------------------------

    def get_source_config(self, source_id: int) -> Dict:
        """Fetch scraping source configuration from database"""
        cursor = self.conn.cursor()
        cursor.execute(
            "SELECT * FROM scraping_sources WHERE source_id = %s AND is_active = 1",
            (source_id,)
        )
        source = cursor.fetchone()
        cursor.close()

        if not source:
            raise ValueError(f"Source ID {source_id} not found or inactive")

        # Parse JSON config
        if source.get('scraping_config'):
            source['config'] = json.loads(source['scraping_config'])
        else:
            source['config'] = {}

        return source

    def should_scrape_url(self, url: str, config: Dict) -> bool:
        """Check if URL should be scraped based on patterns"""
        url_patterns = config.get('url_patterns', {})
        include_patterns = url_patterns.get('include', [])
        exclude_patterns = url_patterns.get('exclude', [])

        # Check exclude patterns first
        for pattern in exclude_patterns:
            pattern = pattern.strip()
            if pattern and pattern in url:
                return False

        # If include patterns exist, URL must match at least one
        if include_patterns:
            return any(p.strip() in url for p in include_patterns if p.strip())

        return True

    # ------------------------------------------------------------------
    # Page scraping
    # ------------------------------------------------------------------

    def scrape_page(self, url: str, source_id: int, config: Dict,
                    depth: int = 0, parent_url: str = None):
        """Scrape a single page and optionally follow links"""
        max_depth = config.get('max_depth', 2)
        follow_links = config.get('follow_links', True)

        # Normalize URL
        url = self.normalize_url(url)

        # Check if already visited
        if url in self.visited_urls:
            return

        # Check depth limit
        if depth > max_depth:
            return

        # Check for non-HTML file extension
        if self.should_skip_url(url):
            print(f"  ⊘ Skipped (non-HTML resource): {url}")
            self.skipped_count += 1
            self.visited_urls.add(url)
            return

        # Check URL patterns
        if not self.should_scrape_url(url, config):
            print(f"  ⊘ Skipped (pattern): {url}")
            self.skipped_count += 1
            self.visited_urls.add(url)
            return

        try:
            print(f"{'  ' * depth}→ Scraping [{depth}]: {url}")

            # Mark as visited AFTER we start scraping to ensure base URL gets scraped
            self.visited_urls.add(url)

            response = self.session.get(url, timeout=self.request_timeout, verify=True, allow_redirects=True)
            response.raise_for_status()
            
            # Check if URL was redirected
            final_url = response.url
            if final_url != url:
                # URL was redirected - use the final URL instead
                final_url_normalized = self.normalize_url(final_url)
                print(f"    ↪ Redirected to: {final_url_normalized}")
                
                # Mark both URLs as visited
                self.visited_urls.add(final_url_normalized)
                
                # Use the final URL for saving content
                url = final_url_normalized

            # Only process HTML content
            content_type = response.headers.get('Content-Type', '')
            if 'text/html' not in content_type.lower():
                print(f"  ⊘ Skipped (not HTML: {content_type.split(';')[0]}): {url}")
                self.skipped_count += 1
                return

            soup = BeautifulSoup(response.content, 'html.parser')

            # Extract main content (smart extraction)
            data = self.extract_main_content(soup, config)

            if data['content'] and len(data['content'].strip()) > 50:
                # Post-extraction content cleaning
                data['content'] = self._clean_extracted_text(data['content'])

                if len(data['content'].strip()) > 50:
                    result = self.save_scraped_content(
                        source_id, url, data,
                        parent_url=parent_url,
                        crawl_depth=depth,
                    )

                status_icons = {
                    'new': '✓ New',
                    'updated': '↻ Updated',
                    'unchanged': '= Unchanged',
                    'failed': '✗ Failed',
                }
                title_preview = (data['title'][:50] + '...') if data['title'] and len(data['title']) > 50 else (data['title'] or 'Untitled')
                print(f"    {status_icons.get(result, '?')}: {title_preview}")
            else:
                print(f"  ⊘ Skipped (too little content): {url}")
                self.skipped_count += 1

            # Follow links if enabled and within depth
            if follow_links and depth < max_depth:
                self._follow_links(soup, url, source_id, config, depth)

            # Explicit cleanup to prevent memory leaks
            soup.decompose()
            del soup
            del response

        except requests.HTTPError as e:
            status_code = e.response.status_code if e.response is not None else 'unknown'
            print(f"  ✗ HTTP {status_code}: {url}")
            self.failed_count += 1
        except requests.ConnectionError as e:
            print(f"  ✗ Connection failed: {url}")
            self.failed_count += 1
        except requests.Timeout:
            print(f"  ✗ Timeout: {url}")
            self.failed_count += 1
        except requests.RequestException as e:
            print(f"  ✗ Request failed: {e}")
            self.failed_count += 1
        except Exception as e:
            print(f"  ✗ Error scraping {url}: {e}")
            self.failed_count += 1

    def _follow_links(self, soup: BeautifulSoup, current_url: str,
                      source_id: int, config: Dict, depth: int, **kwargs):
        """Extract and follow links from a page."""
        cursor = self.conn.cursor()
        cursor.execute(
            "SELECT base_url FROM scraping_sources WHERE source_id = %s",
            (source_id,)
        )
        result = cursor.fetchone()
        cursor.close()

        if not result:
            return

        base_domain = urlparse(result['base_url']).netloc.lower()
        links_to_follow = set()

        for link in soup.find_all('a', href=True):
            href = link['href'].strip()
            if not href or href.startswith(('#', 'javascript:', 'mailto:', 'tel:')):
                continue
            absolute_url = urljoin(current_url, href)
            normalized = self.normalize_url(absolute_url)
            parsed = urlparse(normalized)
            if parsed.netloc.lower() != base_domain:
                continue
            if self.should_skip_url(normalized):
                continue
            if normalized in self.visited_urls:
                continue
            links_to_follow.add(normalized)

        for link_url in sorted(links_to_follow):
            time.sleep(0.5)
            self.scrape_page(link_url, source_id, config, depth + 1, parent_url=current_url)

    # ------------------------------------------------------------------
    # New mode: scan-missing — discover undiscovered links
    # ------------------------------------------------------------------

    def scan_missing_links(self, source_id: int, dry_run: bool = False) -> Dict:
        """BFS crawl from base_url to find pages not yet in scraped_content.

        Unlike the old approach (revisiting already-scraped pages), this starts
        fresh from the seed URL and discovers ALL reachable internal URLs,
        then diffs against the DB to find what's missing.
        """
        self.connect_db()
        try:
            cursor = self.conn.cursor()

            # ── Get source config ──────────────────────────────────────────
            cursor.execute(
                "SELECT base_url, scraping_config FROM scraping_sources WHERE source_id = %s",
                (source_id,)
            )
            row = cursor.fetchone()
            if not row:
                raise ValueError(f"Source {source_id} not found")

            base_url    = self.normalize_url(row['base_url'])
            config      = json.loads(row.get('scraping_config') or '{}')
            max_depth   = config.get('max_depth', 3)
            base_domain = urlparse(base_url).netloc.lower()

            # ── Load already-known URLs and blocked URLs ──────────────────────────────────────────
            # Load blocked URLs first
            cursor.execute("SELECT page_url FROM blocked_urls WHERE source_id = %s", (source_id,))
            blocked_urls = {r['page_url'] for r in cursor.fetchall()}
            if blocked_urls:
                print(f"Blocked: {len(blocked_urls)} URLs (will be skipped)")
            
            cursor.execute("SELECT page_url FROM scraped_content WHERE source_id = %s", (source_id,))
            scraped_urls = {r['page_url'] for r in cursor.fetchall()}

            cursor.execute(
                "SELECT page_url FROM scrape_link_queue WHERE source_id = %s AND status != 'skipped'",
                (source_id,)
            )
            queued_urls = {r['page_url'] for r in cursor.fetchall()}
            known_urls  = scraped_urls | queued_urls | blocked_urls  # Include blocked URLs in known

            print(f"Known: {len(scraped_urls)} scraped, {len(queued_urls)} queued.")
            print(f"BFS from: {base_url}  (max_depth={max_depth})")

            # ── BFS crawl (URL discovery only, no content save) ────────────────
            to_visit: List[tuple] = [(base_url, 0, None)]  # (url, depth, parent)
            visited:  set = set()
            discovered: List[Dict] = []
            discovered_set: set = set()

            while to_visit:
                url, depth, parent = to_visit.pop(0)
                norm = self.normalize_url(url)
                if norm in visited:
                    continue
                visited.add(norm)

                if self.should_skip_url(norm):
                    continue

                # Record as missing if not already known
                if norm not in known_urls and norm not in discovered_set:
                    discovered.append({
                        'url':             norm,
                        'discovered_from': parent,
                        'depth':           depth,
                    })
                    discovered_set.add(norm)

                # Don't recurse beyond max_depth
                if depth >= max_depth:
                    continue

                # Fetch page to extract links
                try:
                    resp = self.session.get(norm, timeout=12, allow_redirects=True)
                    if resp.status_code >= 400:
                        continue
                    soup = BeautifulSoup(resp.content, 'html.parser')
                    for link in soup.find_all('a', href=True):
                        href = link['href'].strip()
                        if not href or href.startswith(('#', 'javascript:', 'mailto:', 'tel:')):
                            continue
                        abs_url = self.normalize_url(urljoin(norm, href))
                        if urlparse(abs_url).netloc.lower() != base_domain:
                            continue
                        if self.should_skip_url(abs_url):
                            continue
                        if abs_url not in visited:
                            to_visit.append((abs_url, depth + 1, norm))
                    time.sleep(0.2)
                except Exception as e:
                    print(f"  ⚠ Cannot fetch {norm}: {e}")

            print(f"BFS done — visited {len(visited)}, missing {len(discovered)}")

            # ── Write to queue ─────────────────────────────────────────────
            if not dry_run and discovered:
                inserted = 0
                for item in discovered:
                    try:
                        cursor.execute("""
                            INSERT IGNORE INTO scrape_link_queue
                            (source_id, page_url, discovered_from_url, crawl_depth, status)
                            VALUES (%s, %s, %s, %s, 'pending')
                        """, (source_id, item['url'], item['discovered_from'], item['depth']))
                        if cursor.rowcount:
                            inserted += 1
                    except Exception:
                        pass
                self.conn.commit()
                print(f"✓ Inserted {inserted} into scrape_link_queue")

            cursor.close()
            return {
                'discovered': len(discovered),
                'items':      discovered,
                'dry_run':    dry_run,
                'visited':    len(visited),
            }
        finally:
            self.close_db()

    # ------------------------------------------------------------------
    # New mode: scrape-missing — process pending queue items
    # ------------------------------------------------------------------

    def scrape_missing_links(self, source_id: int, limit: int = None) -> Dict:
        """Scrape URLs from scrape_link_queue WHERE status='pending'.
        
        If limit is None, processes ALL pending URLs in batches until queue is empty.
        If limit is set, processes only that many URLs.
        """
        self.connect_db()
        try:
            cursor = self.conn.cursor()
            cursor.execute("SELECT * FROM scraping_sources WHERE source_id = %s", (source_id,))
            source = cursor.fetchone()
            if not source:
                raise ValueError(f"Source {source_id} not found")
            config = json.loads(source.get('scraping_config') or '{}')
            cursor.close()

            # Preload visited URLs once at the start
            self.preload_visited_urls(source_id)
            
            total_processed = 0
            batch_size = 200  # Process in batches to prevent memory issues
            
            # If limit is set, only process that many
            if limit is not None:
                batch_size = min(batch_size, limit)
            
            while True:
                # Fetch next batch of pending queue items
                cursor = self.conn.cursor()
                
                if limit is not None:
                    # If limit is set, only fetch remaining items
                    remaining = limit - total_processed
                    if remaining <= 0:
                        cursor.close()
                        break
                    fetch_count = min(batch_size, remaining)
                else:
                    # No limit - fetch full batch
                    fetch_count = batch_size
                
                cursor.execute("""
                    SELECT queue_id, page_url, discovered_from_url, crawl_depth
                    FROM scrape_link_queue
                    WHERE source_id = %s AND status = 'pending'
                    ORDER BY crawl_depth ASC, discovered_at ASC
                    LIMIT %s
                """, (source_id, fetch_count))
                pending = cursor.fetchall()
                cursor.close()

                if not pending:
                    print(f"\n✓ No more pending URLs in queue. Total processed: {total_processed}")
                    break

                print(f"\n{'='*60}")
                print(f"Processing batch: {len(pending)} URLs (Total so far: {total_processed})")
                print(f"{'='*60}")

                batch_processed = 0
                for item in pending:
                    qid = item['queue_id']
                    url = item['page_url']
                    from_url = item.get('discovered_from_url')
                    depth = item.get('crawl_depth', 1) or 1

                    # Check if URL is blocked - skip immediately
                    if url in self.blocked_urls:
                        print(f"  ⊘ Skipping blocked URL: {url}")
                        try:
                            c_skip = self.conn.cursor()
                            c_skip.execute("""
                                UPDATE scrape_link_queue
                                SET status='skipped', processed_at=NOW()
                                WHERE queue_id=%s
                            """, (qid,))
                            self.conn.commit()
                            c_skip.close()
                        except:
                            pass
                        self.skipped_count += 1
                        continue

                    # Refresh DB connection every 50 pages to prevent timeouts
                    if batch_processed > 0 and batch_processed % 50 == 0:
                        print(f"  ⟳ Refreshing database connection (processed {batch_processed} in batch, {total_processed} total)...")
                        self.refresh_db_connection()

                    try:
                        # Mark as scraping
                        c2 = self.conn.cursor()
                        c2.execute("UPDATE scrape_link_queue SET status='scraping' WHERE queue_id=%s", (qid,))
                        self.conn.commit()
                        c2.close()

                        # Scrape the page with timeout protection
                        self.scrape_page(url, source_id, config, depth=depth, parent_url=from_url)

                        # Mark as done
                        c3 = self.conn.cursor()
                        c3.execute("""
                            UPDATE scrape_link_queue
                            SET status='done', processed_at=NOW()
                            WHERE queue_id=%s
                        """, (qid,))
                        self.conn.commit()
                        c3.close()
                        
                        batch_processed += 1
                        total_processed += 1

                    except Exception as e:
                        print(f"  ✗ Error processing queue item {qid} ({url}): {e}")
                        # Mark as failed
                        try:
                            c_fail = self.conn.cursor()
                            c_fail.execute("""
                                UPDATE scrape_link_queue
                                SET status='failed', processed_at=NOW()
                                WHERE queue_id=%s
                            """, (qid,))
                            self.conn.commit()
                            c_fail.close()
                        except:
                            pass
                        self.failed_count += 1

                    # Small delay to prevent overwhelming the server
                    time.sleep(0.5)

                print(f"Batch complete: {batch_processed} URLs processed")
                
                # If we have a limit and reached it, stop
                if limit is not None and total_processed >= limit:
                    break

            print(f"\n{'='*60}")
            print(f"SCRAPING COMPLETE")
            print(f"{'='*60}")
            print(f"Total pages processed: {total_processed}")
            print(f"Pages visited: {len(self.visited_urls)}")
            print(f"New pages: {self.new_count}")
            print(f"Updated pages: {self.updated_count}")
            print(f"Skipped: {self.skipped_count}")
            print(f"Failed: {self.failed_count}")
            return {'processed': total_processed}
        finally:
            self.close_db()

    # ------------------------------------------------------------------
    # New mode: single — scrape exactly one URL
    # ------------------------------------------------------------------

    def run_single(self, source_id: int, single_url: str):
        """Scrape a single URL and attach it to an existing source."""
        self.connect_db()
        try:
            source = self.get_source_config(source_id)
            config = source['config']
            url = self.normalize_url(single_url)

            print(f"\nSingle-URL scrape: {url}")
            print(f"Source: {source['source_name']} (ID {source_id})")

            self.preload_visited_urls(source_id)
            self.scrape_page(url, source_id, config, depth=1, parent_url=source['base_url'])

            print(f"New: {self.new_count}  Updated: {self.updated_count}  "
                  f"Unchanged: {self.unchanged_count}  Failed: {self.failed_count}")
        finally:
            self.close_db()

    # ------------------------------------------------------------------
    # Connectivity test
    # ------------------------------------------------------------------

    def test_connectivity(self, url: str) -> bool:
        """Test if we can reach the target URL before starting the scrape"""
        parsed = urlparse(url)
        host = parsed.netloc

        # DNS resolution check
        try:
            addr = socket.getaddrinfo(host, 443 if parsed.scheme == 'https' else 80)
            print(f"✓ DNS resolved {host} → {addr[0][4][0]}")
        except socket.gaierror as e:
            print(f"✗ DNS resolution failed for {host}: {e}")
            print(f"  Tip: Check if the server has internet access and DNS is configured.")
            return False

        # HTTP reachability check — use GET with stream=True instead of HEAD
        # Many servers (especially university sites) reject HEAD requests with 405
        try:
            resp = self.session.get(url, timeout=10, allow_redirects=True, stream=True)
            resp.close()  # Close immediately, we only need the status
            print(f"✓ HTTP reachable: {url} (status {resp.status_code})")
            return resp.status_code < 400
        except requests.RequestException as e:
            print(f"✗ HTTP check failed: {e}")
            return False

    # ------------------------------------------------------------------
    # Main run
    # ------------------------------------------------------------------

    def run(self, source_id: int, base_url: str = None):
        """Run the scraper for a specific source"""
        self.connect_db()

        try:
            # Get source configuration
            source = self.get_source_config(source_id)
            url = base_url or source['base_url']
            config = source['config']

            print(f"\n{'='*60}")
            print(f"Starting scrape: {source['source_name']}")
            print(f"Base URL: {url}")
            print(f"Max Depth: {config.get('max_depth', 2)}")
            print(f"{'='*60}\n")

            # Test connectivity before starting
            if not self.test_connectivity(url):
                print(f"\n✗ FAILED: Cannot reach {url}")
                print(f"  The scraper cannot connect to the target website.")
                print(f"  Please check your internet connection and try again.")
                sys.exit(1)

            # Pre-load visited URLs from database (resume-safe)
            self.preload_visited_urls(source_id)

            # If force-refresh is enabled, clear the visited set so we re-check
            # all pages for content changes (hash-based change detection still works)
            if self.force_refresh:
                pre_count = len(self.visited_urls)
                self.visited_urls.clear()
                print(f"↻ Force-refresh: cleared {pre_count} previously-scraped URLs for re-check")

            # Start scraping
            start_time = time.time()
            self.scrape_page(url, source_id, config, depth=0)

            # Update source metadata
            cursor = self.conn.cursor()
            total_saved = self.new_count + self.updated_count
            cursor.execute("""
                UPDATE scraping_sources 
                SET last_scraped = NOW(),
                    success_count = success_count + %s,
                    failure_count = failure_count + %s
                WHERE source_id = %s
            """, (total_saved, self.failed_count, source_id))
            self.conn.commit()
            cursor.close()

            elapsed = time.time() - start_time

            # Print summary
            print(f"\n{'='*60}")
            print(f"Scraping Summary")
            print(f"{'-'*60}")
            print(f"  Pages visited:    {len(self.visited_urls)}")
            print(f"  ✓ New pages:      {self.new_count}")
            print(f"  ↻ Updated pages:  {self.updated_count}")
            print(f"  = Unchanged:      {self.unchanged_count}")
            print(f"  ⊘ Skipped:        {self.skipped_count}")
            print(f"  ✗ Failed:         {self.failed_count}")
            print(f"  Time elapsed:     {elapsed:.2f}s")
            print(f"{'='*60}\n")

            if self.new_count > 0 or self.updated_count > 0:
                print(f"✓ Scraping completed successfully!")
            elif self.unchanged_count > 0:
                print(f"✓ Scraping completed — all pages unchanged.")
            elif self.failed_count > 0:
                print(f"✗ Scraping completed with errors!")
                sys.exit(1)
            else:
                print(f"⊘ Scraping completed — no content found to save.")

        except ValueError as e:
            print(f"✗ Configuration error: {e}")
            sys.exit(1)
        finally:
            self.close_db()


def main():
    parser = argparse.ArgumentParser(description='Web Scraper for Campus AI')
    parser.add_argument('--source-id', type=int, required=True, help='Scraping source ID')
    parser.add_argument('--base-url', type=str, help='Override base URL')
    parser.add_argument('--db-host', default='localhost', help='Database host')
    parser.add_argument('--db-user', default='root', help='Database user')
    parser.add_argument('--db-password', default='', help='Database password')
    parser.add_argument('--db-name', default='campus_ai_db', help='Database name')
    parser.add_argument('--force-refresh', action='store_true',
                        help='Force re-check of all previously scraped pages')
    # New mode arguments
    parser.add_argument('--mode', default='full',
                        choices=['full', 'single', 'scan-missing', 'scrape-missing'],
                        help='Scraping mode')
    parser.add_argument('--single-url', type=str, help='URL to scrape in single mode')
    parser.add_argument('--dry-run', action='store_true',
                        help='In scan-missing: discover links but do not write to DB')
    parser.add_argument('--limit', type=int, default=None,
                        help='Max queue items to process in scrape-missing mode (default: process ALL pending URLs)')

    args = parser.parse_args()

    db_config = {
        'host': args.db_host,
        'user': args.db_user,
        'password': args.db_password,
        'database': args.db_name,
        'charset': 'utf8mb4'
    }

    scraper = WebScraper(db_config)
    scraper.force_refresh = args.force_refresh

    if args.mode == 'full':
        scraper.run(args.source_id, args.base_url)

    elif args.mode == 'single':
        if not args.single_url:
            print('✗ --single-url is required in single mode')
            sys.exit(1)
        scraper.run_single(args.source_id, args.single_url)

    elif args.mode == 'scan-missing':
        result = scraper.scan_missing_links(args.source_id, dry_run=args.dry_run)
        # Output machine-readable JSON summary for PHP to parse
        print('SCAN_RESULT_JSON:' + json.dumps({
            'discovered': result['discovered'],
            'dry_run': result['dry_run'],
            'items': result['items'][:500],   # cap to avoid huge output
        }))

    elif args.mode == 'scrape-missing':
        result = scraper.scrape_missing_links(args.source_id, limit=args.limit)
        print(f"Pages visited: {result['processed']}")
        print(f"New pages: {scraper.new_count}")
        print(f"Updated pages: {scraper.updated_count}")
        print(f"Failed: {scraper.failed_count}")


if __name__ == '__main__':
    main()
