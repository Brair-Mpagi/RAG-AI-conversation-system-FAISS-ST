"""Tests for hybrid FULLTEXT helpers."""

from utils.rag_fulltext import _sanitize_fulltext_query, is_title_like_query


def test_sanitize_fulltext_strips_operators():
    q = _sanitize_fulltext_query('lecturers +computer -science "MMU"')
    assert '+' not in q
    assert 'lecturers' in q


def test_title_like_department_query():
    assert is_title_like_query('Department of Computer Science at MMU')


def test_title_like_quoted():
    assert is_title_like_query('"Faculty of Science Technology"')


def test_not_title_like_short():
    assert not is_title_like_query('hi')
