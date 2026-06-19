"""Tests for cloud endpoint resolving in llm.py."""

from utils.llm import _resolve_cloud_endpoint

def test_resolve_cloud_endpoint():
    # OpenAI provider tests
    assert _resolve_cloud_endpoint("openai", "https://api.openai.com/v1", "gpt-4o") == "https://api.openai.com/v1"
    assert _resolve_cloud_endpoint("openai", "https://api.anthropic.com/v1", "deepseek-v4-pro") == "https://api.deepseek.com/v1"
    assert _resolve_cloud_endpoint("openai", "https://api.anthropic.com/v1", "gpt-4o") == "https://api.openai.com/v1"
    assert _resolve_cloud_endpoint("openai", None, "deepseek-chat") == "https://api.deepseek.com/v1"
    assert _resolve_cloud_endpoint("openai", None, "gpt-4o") == "https://api.openai.com/v1"

    # Anthropic provider tests
    assert _resolve_cloud_endpoint("anthropic", "https://api.anthropic.com/v1", "claude-3") == "https://api.anthropic.com/v1"
    assert _resolve_cloud_endpoint("anthropic", "https://api.openai.com/v1", "claude-3") == "https://api.anthropic.com/v1"
    assert _resolve_cloud_endpoint("anthropic", None, "claude-3") == "https://api.anthropic.com/v1"

    # Gemini provider tests
    assert _resolve_cloud_endpoint("gemini", "https://generativelanguage.googleapis.com/v1beta", "gemini-1.5") == "https://generativelanguage.googleapis.com/v1beta"
    assert _resolve_cloud_endpoint("gemini", "https://api.openai.com/v1", "gemini-1.5") == "https://generativelanguage.googleapis.com/v1beta"
    assert _resolve_cloud_endpoint("gemini", None, "gemini-1.5") == "https://generativelanguage.googleapis.com/v1beta"

    # Custom endpoint preservation
    assert _resolve_cloud_endpoint("openai", "https://custom-openai-proxy.local/v1", "custom-model") == "https://custom-openai-proxy.local/v1"
