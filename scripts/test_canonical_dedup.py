#!/usr/bin/env python3
"""
Test Canonical URL Detection and Deduplication

Tests the canonical URL handler with sample HTML and URLs.
"""

import sys
from pathlib import Path

# Add parent directory to path for imports
sys.path.insert(0, str(Path(__file__).parent))

from canonical_url_handler import CanonicalURLHandler


def test_canonical_extraction():
    """Test extracting canonical URL from HTML."""
    print("\n" + "="*70)
    print("TEST 1: Canonical URL Extraction")
    print("="*70)
    
    html_with_canonical = """
    <html>
    <head>
        <link rel="canonical" href="https://mmu.ac.ug/about-us" />
        <title>About Us</title>
    </head>
    <body>Content here</body>
    </html>
    """
    
    html_without_canonical = """
    <html>
    <head><title>About Us</title></head>
    <body>Content here</body>
    </html>
    """
    
    # Mock connection (not used for extraction)
    class MockConn:
        pass
    
    handler = CanonicalURLHandler(MockConn())
    
    # Test 1: Extract canonical URL
    current_url = "https://mmu.ac.ug/?p=2876"
    canonical = handler.extract_canonical_url(html_with_canonical, current_url)
    
    print(f"Current URL:   {current_url}")
    print(f"Canonical URL: {canonical}")
    assert canonical == "https://mmu.ac.ug/about-us", "Failed to extract canonical URL"
    print("✓ PASS: Canonical URL extracted correctly\n")
    
    # Test 2: No canonical tag
    canonical = handler.extract_canonical_url(html_without_canonical, current_url)
    print(f"Current URL:   {current_url}")
    print(f"Canonical URL: {canonical}")
    assert canonical is None, "Should return None when no canonical tag"
    print("✓ PASS: Returns None when no canonical tag\n")


def test_url_normalization():
    """Test URL normalization."""
    print("\n" + "="*70)
    print("TEST 2: URL Normalization")
    print("="*70)
    
    class MockConn:
        pass
    
    handler = CanonicalURLHandler(MockConn())
    
    test_cases = [
        ("https://mmu.ac.ug/about-us/", "https://mmu.ac.ug/about-us"),
        ("https://mmu.ac.ug/about-us#section", "https://mmu.ac.ug/about-us"),
        ("https://MMU.AC.UG/About-Us", "https://mmu.ac.ug/About-Us"),
        ("https://mmu.ac.ug/?b=2&a=1", "https://mmu.ac.ug/?a=1&b=2"),
    ]
    
    for original, expected in test_cases:
        normalized = handler.normalize_url(original)
        print(f"Original:   {original}")
        print(f"Normalized: {normalized}")
        print(f"Expected:   {expected}")
        assert normalized == expected, f"Normalization failed for {original}"
        print("✓ PASS\n")


def test_alias_detection():
    """Test WordPress alias pattern detection."""
    print("\n" + "="*70)
    print("TEST 3: Alias Pattern Detection")
    print("="*70)
    
    class MockConn:
        pass
    
    handler = CanonicalURLHandler(MockConn())
    
    alias_urls = [
        "https://mmu.ac.ug/?p=2876",
        "https://mmu.ac.ug/2024/02/28?p=2876",
        "https://mmu.ac.ug/2023/06/23?p=2876",
    ]
    
    clean_urls = [
        "https://mmu.ac.ug/about-us",
        "https://mmu.ac.ug/contact",
        "https://mmu.ac.ug/news/article-title",
    ]
    
    print("Testing alias URLs (should be detected):")
    for url in alias_urls:
        is_alias = handler.is_likely_alias(url)
        print(f"  {url}")
        print(f"  → Is alias: {is_alias}")
        assert is_alias, f"Failed to detect alias: {url}"
        print("  ✓ PASS\n")
    
    print("Testing clean URLs (should NOT be detected as aliases):")
    for url in clean_urls:
        is_alias = handler.is_likely_alias(url)
        print(f"  {url}")
        print(f"  → Is alias: {is_alias}")
        assert not is_alias, f"False positive alias detection: {url}"
        print("  ✓ PASS\n")


def test_url_comparison():
    """Test URL comparison for canonical preference."""
    print("\n" + "="*70)
    print("TEST 4: URL Canonical Preference")
    print("="*70)
    
    from cleanup_duplicates import normalize_url_for_comparison
    
    urls = [
        "https://mmu.ac.ug/2024/02/28?p=2876",  # Worst: has query + long
        "https://mmu.ac.ug/?p=2876",            # Bad: has query
        "https://mmu.ac.ug/about-us-page",      # Good: clean but longer
        "https://mmu.ac.ug/about-us",           # Best: clean and short
    ]
    
    sorted_urls = sorted(urls, key=normalize_url_for_comparison)
    
    print("URLs sorted by canonical preference (best first):")
    for i, url in enumerate(sorted_urls, 1):
        print(f"  {i}. {url}")
    
    assert sorted_urls[0] == "https://mmu.ac.ug/about-us", "Canonical preference sorting failed"
    print("\n✓ PASS: Canonical URL correctly identified as best\n")


if __name__ == '__main__':
    print("\n" + "="*70)
    print("CANONICAL URL & DEDUPLICATION TESTS")
    print("="*70)
    
    try:
        test_canonical_extraction()
        test_url_normalization()
        test_alias_detection()
        test_url_comparison()
        
        print("\n" + "="*70)
        print("ALL TESTS PASSED ✓")
        print("="*70 + "\n")
        
    except AssertionError as e:
        print(f"\n✗ TEST FAILED: {e}\n")
        sys.exit(1)
    except Exception as e:
        print(f"\n✗ ERROR: {e}\n")
        import traceback
        traceback.print_exc()
        sys.exit(1)
