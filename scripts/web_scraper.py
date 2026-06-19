#!/usr/bin/env python3
"""
Web Scraper for Campus AI Chatbot System
Scrapes content from configured sources and stores in database.

Features:
- Incremental scraping (insert new, update changed, skip unchanged)
- WordPress/Kingster theme-aware main-content extraction
- Aggressive navigation, menu, sidebar, footer removal
- Duplicate-line and menu-item filtering
- Structured content extraction (headings, paragraphs, lists, tables)
- JSON sections output suitable for RAG vector database
- URL normalization (fragments, trailing slashes, query dedup)
- Content hashing for change detection (SHA-256)
- Version history tracking in scraped_content_history
- Metadata extraction (author, publish date, category)
- Graceful error handling (404s, non-HTML, timeouts, login walls)
- Resume-safe: pre-loads existing URLs from database
- Exhaustive recursive crawling: sitemap.xml, robots.txt, paginated pages
- Login-wall detection and skipping
"""

import argparse
import hashlib
import json
import re
import socket
import sys
import time
from pathlib import Path

# Allow imports from backend utils (content enrichment)
_BACKEND_DIR = Path(__file__).resolve().parent.parent / "backend"
if str(_BACKEND_DIR) not in sys.path:
    sys.path.insert(0, str(_BACKEND_DIR))

from utils.content_enrichment import build_rule_enrichment  # noqa: E402
from canonical_url_handler import CanonicalURLHandler  # noqa: E402
from collections import deque
from datetime import datetime
from urllib.parse import urljoin, urlparse, urlunparse, parse_qs, urlencode
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from bs4 import BeautifulSoup, NavigableString, Tag
import pymysql
import pymysql.cursors
from typing import List, Dict, Set, Optional, Tuple

try:
    from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError
    PLAYWRIGHT_AVAILABLE = True
except ImportError:
    PLAYWRIGHT_AVAILABLE = False


# ---------------------------------------------------------------------------
# File extensions to skip (non-HTML resources)
# ---------------------------------------------------------------------------
SKIP_EXTENSIONS = {
    '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.ico', '.bmp', '.tiff',
    '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
    '.zip', '.rar', '.gz', '.tar', '.7z',
    '.mp3', '.mp4', '.avi', '.mov', '.wmv', '.flv', '.wav', '.ogg',
    '.css', '.js', '.json', '.xml', '.rss',
    '.exe', '.msi', '.dmg', '.apk',
    '.woff', '.woff2', '.ttf', '.eot',
}

# ---------------------------------------------------------------------------
# WordPress / Kingster theme – aggressive noise removal selectors
# ---------------------------------------------------------------------------
NOISE_SELECTORS = [
    # Structural
    'nav', 'header', 'footer', 'aside',
    # ARIA roles
    '[role="navigation"]', '[role="banner"]', '[role="contentinfo"]',
    '[role="complementary"]',
    # Generic nav classes
    '.breadcrumb', '.breadcrumbs', '#breadcrumbs', '.breadcrumb-trail',
    '.sidebar', '#sidebar', '.side-bar', '.widget-area', '.widget',
    '.menu', '.nav-menu', '.main-menu', '.navigation', '.sub-menu',
    '.mega-menu', '.dropdown-menu',
    # Header / Footer
    '.footer', '#footer', '.site-footer', '.footer-widget',
    '.header', '#header', '.site-header', '.top-bar',
    # WordPress-specific
    '.wp-block-navigation', '.wp-block-template-part',
    '.comment-area', '#comments', '.comments', '.comment-respond',
    '.post-navigation', '.nav-links', '.paging-navigation',
    # Kingster / Goodlayers theme
    '.kingster-top-bar', '.kingster-header-wrap', '.kingster-navigation',
    '.kingster-footer-wrapper', '.kingster-footer-back', '.kingster-copyright',
    '.kingster-sidebar-wrap', '.kingster-left-sidebar', '.kingster-right-sidebar',
    '.kingster-column-service-item',  # repeated icon boxes in nav
    '.gdlr-core-navigation-item', '.gdlr-core-breadcrumb-item',
    '.gdlr-core-social-share', '.gdlr-core-social-network-item',
    '.gdlr-core-sidebar',
    # Banners / Ads / Popups
    '.cookie-banner', '.cookie-notice', '.cookie-bar',
    '.social-share', '.social-links', '.social-icons',
    '.advertisement', '.ad-banner', '.ads',
    '.popup', '.modal', '.overlay',
    # Pagination
    '.pagination', '.wp-pagenavi', '.page-links',
    # Scripts / styles
    'script', 'style', 'noscript', 'iframe',
    # Forms (login, search, contact – also used to detect login walls)
    'form',
    # Search bars
    '.search-bar', '.search-form', '#searchform',
    # Back-to-top / utility
    '.back-to-top', '#back-to-top', '.gdlr-core-scroll-snap-section',
]

# ---------------------------------------------------------------------------
# Selectors to try for finding main content (priority order)
# WordPress / Kingster first, then generic
# ---------------------------------------------------------------------------
MAIN_CONTENT_SELECTORS = [
    # Kingster / Goodlayers
    '.gdlr-core-page-builder-body',
    '.kingster-page-wrapper',
    '.kingster-content-wrap',
    '.kingster-pbf-wrapper',
    # WordPress core
    '.entry-content',
    '.post-content',
    '.page-content',
    '.content-area',
    '#content',
    '.content',
    # Semantic HTML5
    'main',
    'article',
    '[role="main"]',
    # Generic IDs/classes
    '#main-content', '#maincontent', '#main_content',
    '.main-content', '.maincontent', '.main_content',
    '#body-content', '.body-content',
    '.post-entry',
    '.single-content',
]

# ---------------------------------------------------------------------------
# Patterns that indicate a line is a navigation item (to be filtered out)
# ---------------------------------------------------------------------------
NAV_LINE_PATTERNS = [
    re.compile(r'^(home|about\s*us?|contact\s*us?|search|login|log\s*in|sign\s*in|register|'
               r'menu|navigation|skip\s*to|back\s*to\s*top|read\s*more|click\s*here|'
               r'view\s*all|see\s*all|learn\s*more|get\s*started|apply\s*now|'
               r'admissions?|academics?|governance|staff\s*directory|university\s*services|'
               r'academic\s*units?|students?\s*portal|e[-\s]?learning|library|'
               r'research|news|events?|gallery|alumni|giving|careers?|'
               r'quick\s*links?|useful\s*links?|site\s*map|sitemap|'
               r'facebook|twitter|instagram|linkedin|youtube|whatsapp|'
               r'privacy\s*policy|terms\s*(of\s*(use|service))?|disclaimer|'
               r'copyright|all\s*rights\s*reserved)$',
               re.IGNORECASE),
]

# Single-word or very-short lines that are almost certainly menu items
SHORT_NAV_MAX_WORDS = 3   # lines with ≤ this many words checked further
SHORT_NAV_MAX_CHARS = 40  # and fewer than this many characters


# ===========================================================================
# Playwright Renderer
# ===========================================================================
class PlaywrightRenderer:
    def __init__(self):
        self.playwright = None
        self.browser = None
        self.context = None

    def __enter__(self):
        if not PLAYWRIGHT_AVAILABLE:
            return None
        self.playwright = sync_playwright().start()
        self.browser = self.playwright.chromium.launch(headless=True)
        self.context = self.browser.new_context(
            user_agent='Campus-AI-Bot/2.0 (Educational Purpose)',
            viewport={'width': 1920, 'height': 1080}
        )
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        if self.context:
            self.context.close()
        if self.browser:
            self.browser.close()
        if self.playwright:
            self.playwright.stop()

    def fetch_html(self, url: str, timeout: int = 30000) -> Tuple[int, str]:
        if not self.context:
            raise RuntimeError("Playwright context not initialized")
        page = self.context.new_page()
        try:
            # Wait until network is mostly idle to capture JS-rendered content
            response = page.goto(url, wait_until='networkidle', timeout=timeout)
            status = response.status if response else 200
            # Small extra wait for late-rendered JS elements like Elementor cards
            page.wait_for_timeout(2000)
            html = page.content()
            return status, html
        finally:
            page.close()


# ===========================================================================
# Helper: detect login-wall pages
# ===========================================================================
def is_login_wall(soup: BeautifulSoup, response_url: str) -> bool:
    """Return True if the page appears to require authentication."""
    # URL hints
    url_lower = response_url.lower()
    if any(kw in url_lower for kw in ('/login', '/sign-in', '/signin', '/wp-login', '/account', '/my-account')):
        return True

    # Form with password field
    if soup.find('input', {'type': 'password'}):
        return True

    # Redirect message in body text
    body_text = soup.get_text(' ', strip=True).lower()
    login_phrases = ['you must be logged in', 'please log in', 'login required',
                     'restricted access', 'members only', 'sign in to continue']
    if any(phrase in body_text for phrase in login_phrases):
        return True

    return False


# ===========================================================================
# WebScraper class
# ===========================================================================
class WebScraper:
    def __init__(self, db_config: Dict):
        """Initialize web scraper with database configuration"""
        self.db_config = db_config
        self.conn = None
        self.visited_urls: Set[str] = set()

        # Counters
        self.new_count = 0
        self.updated_count = 0
        self.unchanged_count = 0
        self.skipped_count = 0
        self.failed_count = 0

        self.playwright_renderer = None
        self.canonical_handler = None  # Will be initialized after DB connection

        # Set up requests session with retries
        self.session = requests.Session()
        retry_strategy = Retry(
            total=3,
            backoff_factor=1,
            status_forcelist=[429, 500, 502, 503, 504],
        )
        adapter = HTTPAdapter(max_retries=retry_strategy)
        self.session.mount("http://", adapter)
        self.session.mount("https://", adapter)
        self.session.headers.update({
            'User-Agent': 'Campus-AI-Bot/2.0 (Educational Purpose)',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
        })
        self.request_timeout = 30

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
            
            # Initialize canonical handler after DB connection
            self.canonical_handler = CanonicalURLHandler(self.conn)
            
        except pymysql.Error as e:
            print(f"✗ Database connection failed: {e}")
            sys.exit(1)

    def close_db(self):
        """Close database connection"""
        if self.conn and self.conn.open:
            self.conn.close()
            print("✓ Database connection closed")

    def preload_visited_urls(self, source_id: int):
        """Load already-scraped URLs from database to avoid re-scraping.

        Scoped to this source only — URLs deleted from this source but
        present in another source will NOT be skipped, so they can be
        rediscovered by a missing-links scan.
        """
        cursor = self.conn.cursor()
        cursor.execute(
            "SELECT page_url FROM scraped_content WHERE source_id = %s",
            (source_id,)
        )
        rows = cursor.fetchall()
        cursor.close()
        for row in rows:
            self.visited_urls.add(row['page_url'])
        if self.visited_urls:
            print(f"✓ Pre-loaded {len(self.visited_urls)} already-scraped URLs for source {source_id}")

    # ------------------------------------------------------------------
    # URL normalization
    # ------------------------------------------------------------------

    @staticmethod
    def normalize_url(url: str) -> str:
        """Normalize URL to prevent duplicates from minor differences."""
        parsed = urlparse(url)
        scheme = parsed.scheme.lower()
        netloc = parsed.netloc.lower()
        path = parsed.path
        if path != '/' and path.endswith('/'):
            path = path.rstrip('/')
        query_params = parse_qs(parsed.query, keep_blank_values=True)
        sorted_query = urlencode(
            sorted(query_params.items()), doseq=True
        ) if query_params else ''
        normalized = urlunparse((scheme, netloc, path, parsed.params, sorted_query, ''))
        return normalized

    @staticmethod
    def should_skip_url(url: str) -> bool:
        """Check if URL points to a non-HTML resource based on extension."""
        parsed = urlparse(url)
        path_lower = parsed.path.lower()
        for ext in SKIP_EXTENSIONS:
            if path_lower.endswith(ext):
                return True
        return False

    # ------------------------------------------------------------------
    # Content hashing
    # ------------------------------------------------------------------

    @staticmethod
    def compute_hash(content: str) -> str:
        """Compute SHA-256 hash of content for change detection."""
        return hashlib.sha256(content.encode('utf-8')).hexdigest()

    # ------------------------------------------------------------------
    # Navigation-line filtering
    # ------------------------------------------------------------------

    @staticmethod
    def _is_nav_line(line: str) -> bool:
        """Return True if a text line looks like a navigation menu item."""
        stripped = line.strip()
        if not stripped:
            return False

        # Check explicit nav patterns
        for pattern in NAV_LINE_PATTERNS:
            if pattern.match(stripped):
                return True

        # Very short lines (likely menu labels) that contain no sentence-like punctuation
        words = stripped.split()
        if (len(words) <= SHORT_NAV_MAX_WORDS
                and len(stripped) <= SHORT_NAV_MAX_CHARS
                and not any(c in stripped for c in ('.', ',', ';', ':', '(', ')'))):
            # Extra check: if it looks like a proper noun sequence or title case nav
            if stripped.istitle() or stripped.isupper():
                return True

        return False

    @classmethod
    def _filter_nav_lines(cls, text: str) -> str:
        """Remove navigation-looking lines and deduplicate the text."""
        lines = text.splitlines()
        seen: Set[str] = set()
        filtered: List[str] = []

        for line in lines:
            stripped = line.strip()
            # Skip empty duplicates
            if stripped in seen and not stripped:
                continue
            # Skip nav lines
            if cls._is_nav_line(stripped):
                continue
            # Deduplicate non-empty lines
            if stripped:
                if stripped in seen:
                    continue
                seen.add(stripped)
            filtered.append(line)

        # Collapse multiple blank lines
        result = re.sub(r'\n{3,}', '\n\n', '\n'.join(filtered))
        return result.strip()

    # ------------------------------------------------------------------
    # Table extraction helper
    # ------------------------------------------------------------------

    @staticmethod
    def _extract_table_text(table_elem) -> str:
        """Convert an HTML table to plain-text tab-separated rows."""
        rows_text = []
        for tr in table_elem.find_all('tr'):
            cells = [td.get_text(separator=' ', strip=True)
                     for td in tr.find_all(['td', 'th'])]
            if any(c for c in cells):
                rows_text.append(' | '.join(cells))
        return '\n'.join(rows_text)

    # ------------------------------------------------------------------
    # Main content extraction (WordPress/Kingster aware)
    # ------------------------------------------------------------------

    def extract_main_content(self, soup: BeautifulSoup, config: Dict) -> Dict:
        """Extract only the main content of a page, excluding noise.

        Strategy:
        1. Extract title and metadata BEFORE removing noise elements
        2. Remove all noise/nav/footer/sidebar elements aggressively
        3. Locate main content area using WordPress/Kingster-aware selectors
        4. Extract structured text (headings, paragraphs, lists, tables)
        5. Filter nav lines and deduplicate
        6. Build sections list for RAG vector database
        """
        selectors = config.get('selectors', {})

        # Step 1: Title BEFORE removing elements
        title_selector = selectors.get('title', 'h1, title')
        title_elem = soup.select_one(title_selector)
        title = title_elem.get_text(strip=True) if title_elem else ''

        # Step 2: Metadata BEFORE removing elements
        metadata = self._extract_metadata(soup)

        # Step 3: Remove all noise elements aggressively
        noise_selectors_list = NOISE_SELECTORS[:]
        custom_exclude = selectors.get('exclude', '')
        if custom_exclude:
            noise_selectors_list.extend(
                [s.strip() for s in custom_exclude.split(',') if s.strip()]
            )

        for sel in noise_selectors_list:
            try:
                for elem in soup.select(sel):
                    elem.decompose()
            except Exception:
                pass  # Skip invalid selectors

        # Additional: remove any <ul>/<ol> that look like nav menus
        # (contain only short single-link <li> items)
        self._remove_nav_lists(soup)

        # Step 4: Find main content area
        content_elem = None

        custom_selector = selectors.get('content', '')
        if custom_selector and custom_selector.lower() != 'body':
            content_elem = soup.select_one(custom_selector)

        if not content_elem:
            for sel in MAIN_CONTENT_SELECTORS:
                content_elem = soup.select_one(sel)
                if content_elem:
                    break

        if not content_elem:
            content_elem = soup.find('body')

        if not content_elem:
            return {
                'title': title, 'content': '', 'description': '',
                'sections': [],
                'meta_author': None, 'meta_publish_date': None, 'meta_category': None,
            }

        # Step 5: Extract structured text + sections
        content, sections = self._extract_structured_text_with_sections(content_elem)

        # Step 6: Filter nav lines and deduplicate
        content = self._filter_nav_lines(content)

        # Step 7: Description meta tag
        meta_desc = soup.find('meta', attrs={'name': 'description'})
        description = meta_desc.get('content', '') if meta_desc else ''

        return {
            'title': title,
            'content': content,
            'description': description,
            'sections': sections,
            'meta_author': metadata.get('author'),
            'meta_publish_date': metadata.get('publish_date'),
            'meta_category': metadata.get('category'),
        }

    @staticmethod
    def _remove_nav_lists(soup: BeautifulSoup):
        """Remove <ul>/<ol> elements that appear to be navigation lists.

        Heuristic: a list where ≥ 80% of <li> items contain only an <a> tag
        with very short text (≤ 5 words) is treated as a nav list.
        """
        for list_elem in soup.find_all(['ul', 'ol']):
            items = list_elem.find_all('li', recursive=False)
            if not items:
                continue
            nav_like = 0
            for li in items:
                # Check if the li has only one anchor with short text
                anchors = li.find_all('a')
                text = li.get_text(strip=True)
                words = text.split()
                if len(anchors) >= 1 and len(words) <= 5:
                    nav_like += 1
            if len(items) > 0 and (nav_like / len(items)) >= 0.8:
                list_elem.decompose()

    def _extract_structured_text_with_sections(self, element) -> Tuple[str, List[Dict]]:
        """Extract structured text AND build a sections list for RAG.

        Returns:
            (full_content_string, sections_list)
            sections_list = [{"heading": "...", "text": "..."}, ...]
        """
        lines: List[str] = []
        sections: List[Dict] = []
        current_heading: Optional[str] = None
        current_section_lines: List[str] = []

        def flush_section():
            nonlocal current_heading, current_section_lines
            if current_section_lines:
                section_text = self._filter_nav_lines('\n'.join(current_section_lines))
                if section_text:
                    sections.append({
                        'heading': current_heading or '',
                        'text': section_text,
                    })
            current_section_lines = []

        # Walk through all descendants in document order,
        # only acting on direct block-level tags to avoid duplication
        BLOCK_TAGS = {'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                      'p', 'li', 'blockquote', 'br', 'table',
                      'dt', 'dd', 'pre', 'figcaption'}

        def walk(node):
            nonlocal current_heading

            if isinstance(node, NavigableString):
                text = str(node).strip()
                if text:
                    parent_tag = node.parent.name if node.parent else ''
                    if parent_tag not in ('script', 'style', 'noscript'):
                        lines.append(f" {text} ")
                        current_section_lines.append(text)
                return

            if not isinstance(node, Tag):
                return

            tag = node.name
            if tag is None:
                return

            # For block tags, process them as units (don't recurse into children separately)
            if tag in ('h1', 'h2', 'h3', 'h4', 'h5', 'h6'):
                text = node.get_text(strip=True)
                if text:
                    flush_section()
                    level = int(tag[1])
                    prefix = '#' * level
                    formatted = f"\n{prefix} {text}\n"
                    lines.append(formatted)
                    current_heading = text
                return  # don't recurse

            elif tag == 'table':
                table_text = self._extract_table_text(node)
                if table_text:
                    lines.append(f"\n{table_text}\n")
                    current_section_lines.append(table_text)
                return  # don't recurse

            elif tag == 'p':
                text = node.get_text(strip=True)
                if text:
                    lines.append(f"\n{text}\n")
                    current_section_lines.append(text)
                return  # don't recurse

            elif tag == 'li':
                text = node.get_text(strip=True)
                if text:
                    formatted = f"  • {text}"
                    lines.append(formatted)
                    current_section_lines.append(formatted)
                return  # don't recurse

            elif tag == 'blockquote':
                text = node.get_text(strip=True)
                if text:
                    formatted = f"\n> {text}\n"
                    lines.append(formatted)
                    current_section_lines.append(formatted)
                return  # don't recurse

            elif tag == 'br':
                lines.append("")
                return

            elif tag in ('dt',):
                text = node.get_text(strip=True)
                if text:
                    formatted = f"\n**{text}**"
                    lines.append(formatted)
                    current_section_lines.append(formatted)
                return

            elif tag == 'dd':
                text = node.get_text(strip=True)
                if text:
                    formatted = f"  {text}"
                    lines.append(formatted)
                    current_section_lines.append(formatted)
                return

            elif tag == 'pre':
                text = node.get_text()
                if text.strip():
                    formatted = f"\n```\n{text}\n```\n"
                    lines.append(formatted)
                    current_section_lines.append(formatted)
                return

            elif tag == 'figcaption':
                text = node.get_text(strip=True)
                if text:
                    formatted = f"[{text}]"
                    lines.append(formatted)
                    current_section_lines.append(formatted)
                return

            # For container elements, recurse into children
            for child in node.children:
                walk(child)

        walk(element)
        flush_section()

        raw_text = '\n'.join(lines)
        raw_text = re.sub(r'\n{3,}', '\n\n', raw_text)
        raw_text = raw_text.strip()

        return raw_text, sections

    # ------------------------------------------------------------------
    # Metadata extraction
    # ------------------------------------------------------------------

    @staticmethod
    def _extract_metadata(soup: BeautifulSoup) -> Dict:
        """Extract metadata from page: author, publish date, category."""
        metadata = {}

        for selector in [
            ('meta', {'name': 'author'}),
            ('meta', {'property': 'article:author'}),
            ('meta', {'name': 'dc.creator'}),
        ]:
            tag = soup.find(selector[0], attrs=selector[1])
            if tag and tag.get('content'):
                metadata['author'] = tag['content'].strip()
                break

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

        if 'publish_date' not in metadata:
            time_elem = soup.find('time', attrs={'datetime': True})
            if time_elem:
                metadata['publish_date'] = time_elem['datetime'].strip()

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

    # ------------------------------------------------------------------
    # Database save with incremental logic
    # ------------------------------------------------------------------

    def _enrichment_fields(self, url: str, data: Dict, content_hash: str) -> Dict:
        """Build rule-based enrichment columns for scraped_content."""
        built = build_rule_enrichment(
            page_title=data.get('title') or '',
            page_url=url,
            cleaned_content=data.get('content') or '',
            sections=data.get('sections') or [],
            meta_category=data.get('meta_category'),
        )
        return {
            'sections_json': json.dumps(built['sections_json'], ensure_ascii=False),
            'search_document': built['search_document'],
            'enrichment_json': json.dumps(built['enrichment_json'], ensure_ascii=False),
            'enrichment_hash': content_hash,
            'enrichment_status': 'pending',
        }

    def save_scraped_content(self, source_id: int, url: str, data: Dict, canonical_url: Optional[str] = None) -> str:
        """Save scraped content incrementally.

        Returns: 'new', 'updated', 'unchanged', or 'failed'
        """
        cursor = self.conn.cursor()
        content_hash = self.compute_hash(data['content'])
        enrich = self._enrichment_fields(url, data, content_hash)

        try:
            cursor.execute(
                "SELECT scraped_id, source_id, content_hash, page_title, cleaned_content FROM scraped_content "
                "WHERE page_url = %s LIMIT 1",
                (url,)
            )
            existing = cursor.fetchone()

            if existing:
                if existing['content_hash'] == content_hash:
                    cursor.execute(
                        "UPDATE scraped_content SET scraped_at = NOW(), status = IF(status='new', 'processed', status), canonical_url = %s WHERE scraped_id = %s",
                        (canonical_url, existing['scraped_id'])
                    )
                    self.conn.commit()
                    self.unchanged_count += 1
                    return 'unchanged'
                else:
                    cursor.execute("""
                        INSERT INTO scraped_content_history
                        (scraped_id, page_url, page_title, cleaned_content, content_hash)
                        VALUES (%s, %s, %s, %s, %s)
                    """, (
                        existing['scraped_id'], url,
                        existing['page_title'], existing['cleaned_content'],
                        existing['content_hash']
                    ))

                    cursor.execute("""
                        UPDATE scraped_content
                        SET source_id = %s, page_title = %s, cleaned_content = %s,
                            sections_json = %s, search_document = %s,
                            enrichment_json = %s, enrichment_hash = %s,
                            enrichment_status = %s, enriched_at = NOW(),
                            content_hash = %s,
                            meta_author = %s, meta_publish_date = %s, meta_category = %s,
                            canonical_url = %s,
                            status = 'updated', scraped_at = NOW()
                        WHERE scraped_id = %s
                    """, (
                        source_id,
                        data['title'][:500] if data['title'] else 'Untitled',
                        data['content'],
                        enrich['sections_json'],
                        enrich['search_document'],
                        enrich['enrichment_json'],
                        enrich['enrichment_hash'],
                        enrich['enrichment_status'],
                        content_hash,
                        data.get('meta_author'),
                        data.get('meta_publish_date'),
                        data.get('meta_category'),
                        canonical_url,
                        existing['scraped_id']
                    ))
                    self.conn.commit()
                    self.updated_count += 1
                    return 'updated'
            else:
                cursor.execute("""
                    INSERT INTO scraped_content
                    (source_id, page_url, page_title, cleaned_content,
                     sections_json, search_document, enrichment_json,
                     enrichment_hash, enrichment_status, enriched_at,
                     content_hash, meta_author, meta_publish_date, meta_category,
                     canonical_url, is_canonical, canonical_page_id, url_aliases,
                     status)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), %s, %s, %s, %s, %s, TRUE, NULL, '[]', 'new')
                """, (
                    source_id,
                    url,
                    data['title'][:500] if data['title'] else 'Untitled',
                    data['content'],
                    enrich['sections_json'],
                    enrich['search_document'],
                    enrich['enrichment_json'],
                    enrich['enrichment_hash'],
                    enrich['enrichment_status'],
                    content_hash,
                    data.get('meta_author'),
                    data.get('meta_publish_date'),
                    data.get('meta_category'),
                    canonical_url,
                ))
                self.conn.commit()
                self.new_count += 1
                return 'new'

        except pymysql.IntegrityError:
            self.conn.rollback()
            try:
                cursor.execute("""
                    UPDATE scraped_content
                    SET source_id = %s, page_title = %s, cleaned_content = %s,
                        sections_json = %s, search_document = %s,
                        enrichment_json = %s, enrichment_hash = %s,
                        enrichment_status = %s, enriched_at = NOW(),
                        content_hash = %s,
                        meta_author = %s, meta_publish_date = %s, meta_category = %s,
                        status = 'updated', scraped_at = NOW()
                    WHERE page_url = %s
                """, (
                    source_id,
                    data['title'][:500] if data['title'] else 'Untitled',
                    data['content'],
                    enrich['sections_json'],
                    enrich['search_document'],
                    enrich['enrichment_json'],
                    enrich['enrichment_hash'],
                    enrich['enrichment_status'],
                    content_hash,
                    data.get('meta_author'),
                    data.get('meta_publish_date'),
                    data.get('meta_category'),
                    url,
                ))
                self.conn.commit()
                self.updated_count += 1
                return 'updated'
            except pymysql.Error as e2:
                print(f"    ✗ Fallback update also failed: {e2}")

    def _save_as_alias(self, alias_url: str, canonical_page_id: int, canonical_url: Optional[str], data: Dict):
        """Save URL as an alias to existing canonical page."""
        cursor = self.conn.cursor()
        
        try:
            # Check if alias already exists
            cursor.execute("SELECT scraped_id FROM scraped_content WHERE page_url = %s", (alias_url,))
            if cursor.fetchone():
                return  # Already exists
            
            # Insert as alias (copy from canonical but mark as duplicate)
            cursor.execute("""
                INSERT INTO scraped_content (
                    source_id, page_url, page_title, cleaned_content,
                    sections_json, search_document, enrichment_json,
                    enrichment_hash, enrichment_status,
                    content_hash, meta_author, meta_publish_date, meta_category,
                    canonical_url, is_canonical, canonical_page_id,
                    status, scraped_at
                )
                SELECT 
                    source_id, %s, page_title, cleaned_content,
                    sections_json, search_document, enrichment_json,
                    enrichment_hash, enrichment_status,
                    content_hash, meta_author, meta_publish_date, meta_category,
                    %s, FALSE, %s,
                    'duplicate', NOW()
                FROM scraped_content
                WHERE scraped_id = %s
            """, (alias_url, canonical_url, canonical_page_id, canonical_page_id))
            
            # Add to canonical page's alias list
            if self.canonical_handler:
                self.canonical_handler.add_alias_to_canonical(canonical_page_id, alias_url)
            
            self.conn.commit()
            self.skipped_count += 1
            
        except pymysql.Error as e:
            print(f"    ✗ Failed to save alias: {e}")
            self.conn.rollback()

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

        for pattern in exclude_patterns:
            pattern = pattern.strip()
            if pattern and pattern in url:
                return False

        if include_patterns:
            return any(p.strip() in url for p in include_patterns if p.strip())

        return True

    # ------------------------------------------------------------------
    # Sitemap & robots.txt discovery for exhaustive crawling
    # ------------------------------------------------------------------

    def discover_urls_from_sitemap(self, base_url: str, base_domain: str) -> Set[str]:
        """Fetch and parse sitemap.xml (including sitemap index) for all URLs."""
        discovered: Set[str] = set()
        sitemap_urls_to_try = [
            urljoin(base_url, '/sitemap.xml'),
            urljoin(base_url, '/sitemap_index.xml'),
            urljoin(base_url, '/wp-sitemap.xml'),  # WordPress 5.5+
        ]

        def fetch_sitemap(url: str, depth: int = 0):
            if depth > 5:
                return
            try:
                resp = self.session.get(url, timeout=15, verify=True)
                if resp.status_code != 200:
                    return
                content_type = resp.headers.get('Content-Type', '')
                if 'xml' not in content_type.lower() and not resp.text.strip().startswith('<'):
                    return

                soup = BeautifulSoup(resp.content, 'lxml-xml')

                # Sitemap index: contains <sitemap><loc>...</loc></sitemap>
                for sitemap_tag in soup.find_all('sitemap'):
                    loc = sitemap_tag.find('loc')
                    if loc and loc.text:
                        fetch_sitemap(loc.text.strip(), depth + 1)

                # Regular sitemap: contains <url><loc>...</loc></url>
                for url_tag in soup.find_all('url'):
                    loc = url_tag.find('loc')
                    if loc and loc.text:
                        normalized = self.normalize_url(loc.text.strip())
                        parsed = urlparse(normalized)
                        if parsed.netloc.lower() == base_domain:
                            if not self.should_skip_url(normalized):
                                discovered.add(normalized)

            except Exception as e:
                pass  # Silently skip sitemap errors

        for smap_url in sitemap_urls_to_try:
            fetch_sitemap(smap_url)

        if discovered:
            print(f"✓ Discovered {len(discovered)} URLs from sitemap")
        return discovered

    def discover_urls_from_robots(self, base_url: str, base_domain: str) -> Set[str]:
        """Parse robots.txt for Sitemap directives and collect those URLs."""
        discovered: Set[str] = set()
        robots_url = urljoin(base_url, '/robots.txt')
        try:
            resp = self.session.get(robots_url, timeout=10, verify=True)
            if resp.status_code == 200:
                for line in resp.text.splitlines():
                    line = line.strip()
                    if line.lower().startswith('sitemap:'):
                        sitemap_url = line.split(':', 1)[1].strip()
                        discovered.update(
                            self.discover_urls_from_sitemap(sitemap_url, base_domain)
                        )
        except Exception:
            pass
        return discovered

    # ------------------------------------------------------------------
    # Iterative BFS crawl (exhaustive, avoids recursion depth limits)
    # ------------------------------------------------------------------

    def crawl_bfs(self, start_url: str, source_id: int, config: Dict,
                  base_domain: str, seed_urls: Set[str] = None):
        """Breadth-first crawl of all pages within the same domain.

        This replaces the recursive approach and handles:
        - Unlimited depth (configurable max_depth still respected)
        - Pagination detection (?page=N, /page/N/)
        - Pre-seeded URLs from sitemap/robots
        """
        max_depth = config.get('max_depth', 99)  # Default: crawl everything
        follow_links = config.get('follow_links', True)

        # BFS queue: (url, depth)
        queue: deque = deque()
        queue.append((self.normalize_url(start_url), 0))
        
        stop_signal_file = f"/tmp/scrape_stop_signal_{source_id}"

        # Add sitemap-discovered URLs at depth 1
        if seed_urls:
            for url in seed_urls:
                if url not in self.visited_urls:
                    queue.append((url, 1))

        total_queued = 0

        while queue:
            import os
            if os.path.exists(stop_signal_file):
                print(f"\n[!] STOP SIGNAL RECEIVED. Terminating crawl early...")
                try:
                    os.remove(stop_signal_file)
                except Exception:
                    pass
                break

            url, depth = queue.popleft()

            # Normalize
            url = self.normalize_url(url)

            # Skip if visited
            if url in self.visited_urls:
                continue

            # Skip non-HTML
            if self.should_skip_url(url):
                self.visited_urls.add(url)
                self.skipped_count += 1
                continue

            # Check URL patterns
            if not self.should_scrape_url(url, config):
                self.visited_urls.add(url)
                self.skipped_count += 1
                continue

            # Mark visited
            self.visited_urls.add(url)

            # Scrape the page and collect new links
            new_links = self._scrape_single_page(url, source_id, config, depth)

            # Enqueue new links if within depth limit
            if follow_links and depth < max_depth and new_links:
                for link_url in sorted(new_links):
                    if link_url not in self.visited_urls:
                        queue.append((link_url, depth + 1))
                        total_queued += 1

            # Polite delay
            time.sleep(0.5)

        print(f"\n✓ BFS crawl complete. Total URLs queued during run: {total_queued}")

    def _scrape_single_page(self, url: str, source_id: int,
                             config: Dict, depth: int) -> Set[str]:
        """Scrape one page and return set of discovered internal links."""
        new_links: Set[str] = set()

        try:
            print(f"{'  ' * min(depth, 6)}→ [{depth}] {url}")

            if self.playwright_renderer:
                try:
                    status, html_content = self.playwright_renderer.fetch_html(url, timeout=self.request_timeout * 1000)
                    if status >= 400:
                        response = self.session.get(url, timeout=self.request_timeout, verify=True)
                        response.raise_for_status()
                    soup = BeautifulSoup(html_content, 'html.parser')
                    response_url = url
                except Exception as e:
                    print(f"  ⚠ Playwright failed ({e}), falling back to requests")
                    response = self.session.get(url, timeout=self.request_timeout, verify=True)
                    response.raise_for_status()
                    content_type = response.headers.get('Content-Type', '')
                    if 'text/html' not in content_type.lower():
                        print(f"  ⊘ Not HTML ({content_type.split(';')[0]}): {url}")
                        self.skipped_count += 1
                        return new_links
                    soup = BeautifulSoup(response.content, 'html.parser')
                    response_url = response.url
            else:
                response = self.session.get(url, timeout=self.request_timeout, verify=True)
                response.raise_for_status()

                # Only process HTML
                content_type = response.headers.get('Content-Type', '')
                if 'text/html' not in content_type.lower():
                    print(f"  ⊘ Not HTML ({content_type.split(';')[0]}): {url}")
                    self.skipped_count += 1
                    return new_links

                soup = BeautifulSoup(response.content, 'html.parser')
                response_url = response.url

            # Detect login wall — skip
            if is_login_wall(soup, response_url):
                print(f"  ⊘ Skipped (login required): {url}")
                self.skipped_count += 1
                return new_links

            # Extract canonical URL from HTML
            canonical_url = None
            if self.canonical_handler:
                html_str = str(soup) if self.playwright_renderer else response.text
                canonical_url = self.canonical_handler.extract_canonical_url(html_str, url)
                if canonical_url:
                    print(f"    → Canonical: {canonical_url}")

            # Extract main content (WordPress/Kingster aware)
            data = self.extract_main_content(soup, config)

            if data['content'] and len(data['content'].strip()) > 50:
                # Compute content hash for deduplication
                content_hash = self.compute_hash(data['content'])
                
                # Check if this is a duplicate
                if self.canonical_handler:
                    is_duplicate, canonical_page_id = self.canonical_handler.should_skip_as_duplicate(
                        source_id, url, content_hash, canonical_url
                    )
                    
                    if is_duplicate:
                        # Save as alias instead of new page
                        self._save_as_alias(url, canonical_page_id, canonical_url, data)
                        print(f"    ⊘ Duplicate (alias of canonical page): {data['title'][:60] if data['title'] else 'Untitled'}")
                        return new_links
                
                # Save as new or updated page
                result = self.save_scraped_content(source_id, url, data, canonical_url)
                status_icons = {
                    'new': '✓ New',
                    'updated': '↻ Updated',
                    'unchanged': '= Unchanged',
                    'failed': '✗ Failed',
                }
                title_preview = (
                    (data['title'][:60] + '…')
                    if data['title'] and len(data['title']) > 60
                    else (data['title'] or 'Untitled')
                )
                print(f"    {status_icons.get(result, '?')}: {title_preview}")
            else:
                print(f"  ⊘ Skipped (too little content): {url}")
                self.skipped_count += 1

            # Collect internal links for further crawling
            new_links = self._collect_internal_links(soup, url, source_id)

        except requests.HTTPError as e:
            status_code = e.response.status_code if e.response is not None else 'unknown'
            if status_code in (401, 403):
                print(f"  ⊘ Skipped (auth required {status_code}): {url}")
                self.skipped_count += 1
            else:
                print(f"  ✗ HTTP {status_code}: {url}")
                self.failed_count += 1
        except requests.ConnectionError:
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

        return new_links

    def _collect_internal_links(self, soup: BeautifulSoup, current_url: str,
                                 source_id: int) -> Set[str]:
        """Extract all internal links from a page, including paginated variants."""
        cursor = self.conn.cursor()
        cursor.execute(
            "SELECT base_url FROM scraping_sources WHERE source_id = %s",
            (source_id,)
        )
        result = cursor.fetchone()
        cursor.close()

        if not result:
            return set()

        base_domain = urlparse(result['base_url']).netloc.lower()
        links: Set[str] = set()

        for link in soup.find_all('a', href=True):
            href = link['href'].strip()
            if not href or href.startswith(('#', 'javascript:', 'mailto:', 'tel:', 'data:')):
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

            links.add(normalized)

        # Rely on actual HTML pagination links rather than speculative URL crafting
        # to prevent infinite loop duplicates on non-archival pages.
        # self._add_pagination_variants(current_url, base_domain, links)

        return links

    def _add_pagination_variants(self, url: str, base_domain: str, links: Set[str]):
        """Detect and enqueue WordPress pagination variants of a URL."""
        parsed = urlparse(url)
        path = parsed.path

        # WordPress path-based pagination: /page/N/
        page_match = re.search(r'/page/(\d+)/?$', path)
        if page_match:
            current_page = int(page_match.group(1))
            # Speculatively add next 2 pages
            for next_page in range(current_page + 1, current_page + 3):
                new_path = re.sub(r'/page/\d+/?$', f'/page/{next_page}/', path)
                new_url = self.normalize_url(
                    urlunparse(parsed._replace(path=new_path, query=''))
                )
                if urlparse(new_url).netloc.lower() == base_domain:
                    if new_url not in self.visited_urls:
                        links.add(new_url)
        else:
            # Try adding /page/2/ for archive-style pages
            base_path = path.rstrip('/') + '/page/2/'
            new_url = self.normalize_url(
                urlunparse(parsed._replace(path=base_path, query=''))
            )
            if urlparse(new_url).netloc.lower() == base_domain:
                if new_url not in self.visited_urls:
                    links.add(new_url)

    # ------------------------------------------------------------------
    # Connectivity test
    # ------------------------------------------------------------------

    def test_connectivity(self, url: str) -> bool:
        """Test if we can reach the target URL before starting the scrape"""
        parsed = urlparse(url)
        host = parsed.netloc

        try:
            addr = socket.getaddrinfo(host, 443 if parsed.scheme == 'https' else 80)
            print(f"✓ DNS resolved {host} → {addr[0][4][0]}")
        except socket.gaierror as e:
            print(f"✗ DNS resolution failed for {host}: {e}")
            print(f"  Tip: Check if the server has internet access and DNS is configured.")
            return False

        try:
            resp = self.session.head(url, timeout=10, allow_redirects=True)
            print(f"✓ HTTP reachable: {url} (status {resp.status_code})")
            return True
        except requests.RequestException as e:
            print(f"✗ HTTP check failed: {e}")
            return False

    # ------------------------------------------------------------------
    # Backward-compatible single-page scrape entry (used by recursive callers)
    # ------------------------------------------------------------------

    def scrape_page(self, url: str, source_id: int, config: Dict, depth: int = 0):
        """Legacy entry point – delegates to BFS crawl for exhaustive crawling."""
        # This is called by run() below; we use BFS from here.
        # Kept for API compatibility if called externally.
        base_domain = urlparse(url).netloc.lower()

        # Discover extra URLs via sitemap / robots
        seed_urls = self.discover_urls_from_sitemap(url, base_domain)
        seed_urls |= self.discover_urls_from_robots(url, base_domain)

        self.crawl_bfs(url, source_id, config, base_domain, seed_urls=seed_urls)

    # ------------------------------------------------------------------
    # Queue helpers for missing-links scan
    # ------------------------------------------------------------------

    def _load_known_urls_for_source(self, source_id: int) -> Set[str]:
        """Return the set of all URLs already scraped OR already queued for a source."""
        cursor = self.conn.cursor()
        known: Set[str] = set()

        cursor.execute("SELECT page_url FROM scraped_content WHERE source_id = %s", (source_id,))
        for row in cursor.fetchall():
            known.add(self.normalize_url(row['page_url']))

        cursor.execute(
            "SELECT page_url FROM scrape_link_queue WHERE source_id = %s AND status IN ('pending','done','scraping')",
            (source_id,)
        )
        for row in cursor.fetchall():
            known.add(self.normalize_url(row['page_url']))

        cursor.close()
        return known

    def _queue_missing_url(self, source_id: int, url: str, discovered_from: str, depth: int):
        """Insert a missing URL into scrape_link_queue (ignore if already there)."""
        cursor = self.conn.cursor()
        try:
            cursor.execute("""
                INSERT INTO scrape_link_queue
                    (source_id, page_url, discovered_from_url, crawl_depth, status, discovered_at)
                VALUES (%s, %s, %s, %s, 'pending', NOW())
                ON DUPLICATE KEY UPDATE
                    status = IF(status IN ('done','failed','skipped'), 'pending', status),
                    discovered_from_url = VALUES(discovered_from_url)
            """, (source_id, url, discovered_from, depth))
            self.conn.commit()
        except Exception as e:
            self.conn.rollback()
            print(f"    ✗ Queue insert error: {e}")
        finally:
            cursor.close()

    def scan_missing_links(self, source_id: int, base_url: str = None):
        """
        Smart domain re-indexer: discovers URLs on the domain that are
        NOT yet in scraped_content (or scrape_link_queue) for this source.

        Algorithm:
        1. Load all known URLs (scraped_content + pending queue) for this source
        2. BFS-crawl the domain starting from base_url + all already-scraped pages
        3. For every internal link found, check if it is in known_urls
        4. If NOT known → insert into scrape_link_queue as 'pending'
        5. Never re-scrape or save content — only discover and queue

        Key difference from normal crawl:
        - visited_urls is used to track what we have FETCHED FOR LINK EXTRACTION,
          not what is already scraped. So deleted pages are re-fetched for links.
        - We always start BFS from BOTH base_url AND every currently-scraped page,
          so even if base_url itself was deleted, its links can be rediscovered.
        """
        self.connect_db()
        try:
            source = self.get_source_config(source_id)
            url = base_url or source['base_url']
            config = source['config']
            base_domain = urlparse(url).netloc.lower()

            print(f"\n{'='*60}")
            print(f"Smart Missing-Links Scan: {source['source_name']}")
            print(f"Base URL: {url}")
            print(f"{'='*60}\n")

            # Step 1: Load all known URLs for this source
            known_urls = self._load_known_urls_for_source(source_id)
            print(f"✓ Known URLs for this source (scraped + queued): {len(known_urls)}")

            # Always ensure base_url itself is on the queue if missing
            norm_base = self.normalize_url(url)
            if norm_base not in known_urls:
                print(f"  → Base URL is MISSING — queuing: {norm_base}")
                self._queue_missing_url(source_id, norm_base, url, 0)
                known_urls.add(norm_base)
                self.new_count += 1

            # Step 2: Discover extra seed URLs via sitemap / robots
            seed_urls = self.discover_urls_from_sitemap(url, base_domain)
            seed_urls |= self.discover_urls_from_robots(url, base_domain)

            # Step 3: Build BFS seed set — start from base_url + all scraped pages
            cursor = self.conn.cursor()
            cursor.execute("SELECT page_url FROM scraped_content WHERE source_id = %s", (source_id,))
            scraped_pages = [self.normalize_url(r['page_url']) for r in cursor.fetchall()]
            cursor.close()

            # BFS queue: (url_to_fetch_for_links, depth)
            fetch_visited: Set[str] = set()   # pages we have already fetched for link extraction
            bfs_queue: deque = deque()
            bfs_queue.append((self.normalize_url(url), 0))
            for pg in scraped_pages:
                bfs_queue.append((pg, 1))
            for su in seed_urls:
                bfs_queue.append((su, 1))

            missing_found = 0
            stop_signal_file = f"/tmp/scrape_stop_signal_{source_id}"

            while bfs_queue:
                import os
                if os.path.exists(stop_signal_file):
                    print(f"\n[!] STOP SIGNAL — terminating scan early.")
                    try:
                        os.remove(stop_signal_file)
                    except Exception:
                        pass
                    break

                fetch_url, depth = bfs_queue.popleft()
                fetch_url = self.normalize_url(fetch_url)

                if fetch_url in fetch_visited:
                    continue
                if self.should_skip_url(fetch_url):
                    continue
                if urlparse(fetch_url).netloc.lower() != base_domain:
                    continue

                fetch_visited.add(fetch_url)

                # Fetch this page ONLY to extract its links
                try:
                    print(f"  [scan] {fetch_url}")
                    resp = self.session.get(fetch_url, timeout=self.request_timeout, verify=True)
                    if resp.status_code >= 400:
                        continue
                    content_type = resp.headers.get('Content-Type', '')
                    if 'text/html' not in content_type.lower():
                        continue
                    soup = BeautifulSoup(resp.content, 'html.parser')
                except Exception as e:
                    print(f"    ✗ Fetch error: {e}")
                    continue

                # Extract all internal links
                for link in soup.find_all('a', href=True):
                    href = link['href'].strip()
                    if not href or href.startswith(('#', 'javascript:', 'mailto:', 'tel:', 'data:')):
                        continue
                    abs_url = urljoin(fetch_url, href)
                    norm = self.normalize_url(abs_url)
                    parsed = urlparse(norm)
                    if parsed.netloc.lower() != base_domain:
                        continue
                    if self.should_skip_url(norm):
                        continue
                    if not self.should_scrape_url(norm, config):
                        continue

                    # Is this URL missing from the source?
                    if norm not in known_urls:
                        print(f"    ✦ MISSING: {norm}")
                        self._queue_missing_url(source_id, norm, fetch_url, depth + 1)
                        known_urls.add(norm)   # don't double-queue
                        missing_found += 1
                        self.new_count += 1

                    # Enqueue for further link-extraction if not yet fetched
                    if norm not in fetch_visited:
                        bfs_queue.append((norm, depth + 1))

                time.sleep(0.3)

            print(f"\n{'='*60}")
            print(f"Missing-Links Scan Complete")
            print(f"  Pages fetched for link extraction: {len(fetch_visited)}")
            print(f"  Missing URLs discovered & queued:  {missing_found}")
            print(f"{'='*60}\n")

        except ValueError as e:
            print(f"✗ Configuration error: {e}")
            sys.exit(1)
        finally:
            self.close_db()

    # ------------------------------------------------------------------
    # Scrape pending queue items for a source (--mode scrape-missing)
    # ------------------------------------------------------------------

    def run_scrape_missing(self, source_id: int):
        """Fetch and scrape all 'pending' items in scrape_link_queue for this source."""
        self.connect_db()
        try:
            source = self.get_source_config(source_id)
            config = source['config']

            print(f"\n{'='*60}")
            print(f"Scrape-Missing run: {source['source_name']}")
            print(f"{'='*60}\n")

            cursor = self.conn.cursor()
            cursor.execute("""
                SELECT queue_id, page_url, crawl_depth
                FROM scrape_link_queue
                WHERE source_id = %s AND status = 'pending'
                ORDER BY crawl_depth ASC, discovered_at ASC
            """, (source_id,))
            pending = cursor.fetchall()
            cursor.close()

            print(f"✓ {len(pending)} pending URLs to scrape")
            start_time = time.time()

            for item in pending:
                queue_id = item['queue_id']
                url = item['page_url']
                depth = item['crawl_depth'] or 0

                # Mark as scraping
                c = self.conn.cursor()
                c.execute("UPDATE scrape_link_queue SET status='scraping' WHERE queue_id=%s", (queue_id,))
                self.conn.commit()
                c.close()

                if url in self.visited_urls:
                    c = self.conn.cursor()
                    c.execute("UPDATE scrape_link_queue SET status='done' WHERE queue_id=%s", (queue_id,))
                    self.conn.commit()
                    c.close()
                    self.skipped_count += 1
                    continue

                self.visited_urls.add(url)
                self._scrape_single_page(url, source_id, config, depth)

                # Mark done/failed
                status_val = 'done'
                c = self.conn.cursor()
                c.execute("UPDATE scrape_link_queue SET status=%s, processed_at=NOW() WHERE queue_id=%s",
                          (status_val, queue_id))
                self.conn.commit()
                c.close()

                time.sleep(0.5)

            elapsed = time.time() - start_time
            print(f"\n{'='*60}")
            print(f"Scrape-Missing Summary")
            print(f"  ✓ New pages:      {self.new_count}")
            print(f"  ↻ Updated pages:  {self.updated_count}")
            print(f"  = Unchanged:      {self.unchanged_count}")
            print(f"  ⊘ Skipped:        {self.skipped_count}")
            print(f"  ✗ Failed:         {self.failed_count}")
            print(f"  Time elapsed:     {elapsed:.2f}s")
            print(f"{'='*60}\n")

        except ValueError as e:
            print(f"✗ Configuration error: {e}")
            sys.exit(1)
        finally:
            self.close_db()

    # ------------------------------------------------------------------
    # Main run
    # ------------------------------------------------------------------

    def run(self, source_id: int, base_url: str = None):
        """Run the scraper for a specific source"""
        self.connect_db()

        try:
            source = self.get_source_config(source_id)
            url = base_url or source['base_url']
            config = source['config']

            print(f"\n{'='*60}")
            print(f"Starting scrape: {source['source_name']}")
            print(f"Base URL: {url}")
            print(f"Max Depth: {config.get('max_depth', '∞ (exhaustive)')}")
            print(f"{'='*60}\n")

            if not self.test_connectivity(url):
                print(f"\n✗ FAILED: Cannot reach {url}")
                print(f"  Please check your internet connection and try again.")
                sys.exit(1)

            # Pre-load visited URLs from database (resume-safe)
            self.preload_visited_urls(source_id)

            # Start scraping (BFS exhaustive crawl)
            start_time = time.time()
            
            if PLAYWRIGHT_AVAILABLE:
                print("✓ Using Playwright for JavaScript rendering")
                with PlaywrightRenderer() as renderer:
                    self.playwright_renderer = renderer
                    self.scrape_page(url, source_id, config, depth=0)
            else:
                print("! Playwright not available, using requests only")
                self.playwright_renderer = None
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


# ===========================================================================
# CLI entry point
# ===========================================================================

def main():
    parser = argparse.ArgumentParser(description='Web Scraper for Campus AI')
    parser.add_argument('--source-id', type=int, required=True, help='Scraping source ID')
    parser.add_argument('--base-url', type=str, help='Override base URL')
    parser.add_argument('--db-host', default='localhost', help='Database host')
    parser.add_argument('--db-user', default='root', help='Database user')
    parser.add_argument('--db-password', default='', help='Database password')
    parser.add_argument('--db-name', default='campus_ai_db', help='Database name')
    parser.add_argument(
        '--mode',
        choices=['full', 'scan-missing', 'scrape-missing', 'single'],
        default='full',
        help=(
            'full          — normal BFS crawl, skip already-scraped pages (default)\n'
            'scan-missing  — discover missing URLs on domain, queue them, do NOT scrape\n'
            'scrape-missing— scrape all pending items in scrape_link_queue for this source\n'
            'single        — scrape one page specified by --single-url'
        )
    )
    parser.add_argument('--single-url', type=str, help='URL to scrape (used with --mode single)')
    parser.add_argument('--force-refresh', action='store_true',
                        help='Re-scrape even if content is unchanged (full mode only)')

    args = parser.parse_args()

    db_config = {
        'host': args.db_host,
        'user': args.db_user,
        'password': args.db_password,
        'database': args.db_name,
        'charset': 'utf8mb4'
    }

    scraper = WebScraper(db_config)

    if args.mode == 'scan-missing':
        scraper.scan_missing_links(args.source_id, args.base_url)

    elif args.mode == 'scrape-missing':
        scraper.run_scrape_missing(args.source_id)

    elif args.mode == 'single':
        if not args.single_url:
            print("✗ --mode single requires --single-url")
            sys.exit(1)
        scraper.connect_db()
        try:
            source = scraper.get_source_config(args.source_id)
            config = source['config']
            scraper.preload_visited_urls(args.source_id)
            scraper._scrape_single_page(args.single_url, args.source_id, config, depth=1)
        finally:
            scraper.close_db()

    else:  # full
        if args.force_refresh:
            # Clear visited so all pages get re-evaluated
            scraper.visited_urls = set()
        scraper.run(args.source_id, args.base_url)


if __name__ == '__main__':
    main()