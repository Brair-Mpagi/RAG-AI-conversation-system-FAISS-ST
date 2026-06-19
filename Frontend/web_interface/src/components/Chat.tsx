import { useState, useRef, useEffect, useCallback } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { chat as chatApi, startSession, submitReaction, submitDetailedFeedback, sendHeartbeat } from '../api/client';
import type { ChatResponse, SessionStartRequest, SessionStartResponse } from '../api/client';
import EscalateForm from './EscalateForm';
import FeedbackModal from './FeedbackModal';
import type { FeedbackData } from './FeedbackModal';
import {
  ChatRoot,
  Container,
  Header,
  HeaderContent,
  HeaderImage,
  HeaderText,
  HeaderControls,
  HeaderButton,
  MessagesContainer,
  Message,
  Footer,
  Input,
  SendButton,
  ToggleButton,
  OnlineIndicator,
  MessageFeedbackControls,
  FeedbackButton,
  SuggestedQuestionBtn
} from './Chat.styles';

// ─── Constants ────────────────────────────────────────────────────────────────
const MAX_INPUT_LENGTH = 5000;
const WARN_INPUT_LENGTH = 4500;
const SESSION_ID_KEY = 'campus_chat_session_id';
const SESSION_TOKEN_KEY = 'campus_chat_session_token';
const CONVERSATION_ID_KEY = 'campus_chat_conversation_id';

const SUGGESTED_QUESTIONS = [
  '🎓 What faculties does MMU have?',
  '👨‍💼 Who is MMU\'s current VC?',
  '📅 When does the next intake start?',
  '📍 Where is MMU located?',
  '📝 How do I apply for admission?',
];

// ─── Types ────────────────────────────────────────────────────────────────────
interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
  timestamp: string;
  message_id?: number;
}

// ─── Component ────────────────────────────────────────────────────────────────
export default function Chat() {
  const [isOpen, setIsOpen] = useState(false);
  const [isMaximized, setIsMaximized] = useState(false);
  const [input, setInput] = useState('');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [showEscalateForm, setShowEscalateForm] = useState(false);
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [feedbackState, setFeedbackState] = useState<Map<number, 'up' | 'down' | null>>(new Map());
  const [showFeedbackModal, setShowFeedbackModal] = useState(false);
  const [selectedMessageForFeedback, setSelectedMessageForFeedback] = useState<number | null>(null);
  const [toast, setToast] = useState<{ message: string; type: 'success' | 'error' | 'warning' } | null>(null);
  const [animatingThumb, setAnimatingThumb] = useState<string | null>(null);
  const [rateLimitReset, setRateLimitReset] = useState<number | null>(null);
  const messagesContainerRef = useRef<HTMLDivElement>(null);

  // ── Scroll to bottom ──────────────────────────────────────────────────────
  const scrollToBottom = () => {
    if (messagesContainerRef.current) {
      messagesContainerRef.current.scrollTop = messagesContainerRef.current.scrollHeight;
    }
  };
  useEffect(scrollToBottom, [messages]);

  // ── Toast helper ──────────────────────────────────────────────────────────
  const showToast = useCallback((message: string, type: 'success' | 'error' | 'warning' = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 4000);
  }, []);

  // ── Rate-limit countdown ───────────────────────────────────────────────────
  useEffect(() => {
    if (!rateLimitReset) return;
    const remaining = rateLimitReset - Date.now();
    if (remaining <= 0) { setRateLimitReset(null); return; }
    const timer = setTimeout(() => setRateLimitReset(null), remaining);
    return () => clearTimeout(timer);
  }, [rateLimitReset]);

  // ── Session init + heartbeat ──────────────────────────────────────────────
  useEffect(() => {
    let heartbeatInterval: ReturnType<typeof setInterval>;

    (async () => {
      try {
        // Try to restore existing session from localStorage
        const storedId = localStorage.getItem(SESSION_ID_KEY);
        const storedToken = localStorage.getItem(SESSION_TOKEN_KEY);
        const storedConvId = localStorage.getItem(CONVERSATION_ID_KEY);
        let currentSessionId = storedId ? parseInt(storedId, 10) : null;
        let currentToken = storedToken ?? '';

        if (!currentSessionId) {
          //── Collect device info ──────────────────────────────────────────
          const ua = navigator.userAgent || '';
          const platform = (navigator as any).platform || '';
          const vendor = (navigator as any).vendor || '';
          const screenRes = window.screen ? `${window.screen.width}x${window.screen.height}` : '';

          const payload: SessionStartRequest = {
            interface_type: 'web',
            access_mode: 'guest',
            browser_name: ua,
            browser_version: '',
            os_name: platform,
            os_version: '',
            device_brand: vendor,
            screen_resolution: screenRes,
          };

          // ── Geolocation (IP fallback) ─────────────────────────────────
          const fetchIpGeo = async () => {
            try {
              const res = await fetch('https://get.geojs.io/v1/ip/geo.json');
              const data = await res.json();
              if (data.latitude && data.longitude) payload.location = `${data.latitude},${data.longitude}`;
            } catch {
              try {
                const res2 = await fetch('http://ip-api.com/json/?fields=lat,lon,city,country');
                const data2 = await res2.json();
                if (data2.lat && data2.lon) payload.location = `${data2.lat},${data2.lon}`;
                else if (data2.city && data2.country) payload.location = `${data2.city}, ${data2.country}`;
              } catch { /* geolocation unavailable */ }
            }
          };

          if ('geolocation' in navigator) {
            await new Promise<void>((resolve) => {
              navigator.geolocation.getCurrentPosition(
                (pos) => { payload.location = `${pos.coords.latitude},${pos.coords.longitude}`; resolve(); },
                async () => { await fetchIpGeo(); resolve(); },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
              );
            });
          } else {
            await fetchIpGeo();
          }

          const res: SessionStartResponse = await startSession(payload);
          currentSessionId = res.session_id;
          currentToken = res.session_token;

          // Persist to localStorage
          localStorage.setItem(SESSION_ID_KEY, currentSessionId.toString());
          localStorage.setItem(SESSION_TOKEN_KEY, currentToken);
        }

        setSessionId(currentSessionId);

        // Restore conversation from previous visit
        if (storedConvId) setConversationId(parseInt(storedConvId, 10));

        // ── Heartbeat (every 60 s, with session_token) ───────────────────
        heartbeatInterval = setInterval(async () => {
          if (currentSessionId && currentToken) {
            try {
              await sendHeartbeat({ session_id: currentSessionId, session_token: currentToken });
            } catch (err) {
              console.warn('Heartbeat failed', err);
            }
          }
        }, 60000);

      } catch (e) {
        console.warn('Session start failed; proceeding in ephemeral mode', e);
      }
    })();

    return () => { if (heartbeatInterval) clearInterval(heartbeatInterval); };
  }, []);

  // ── Persist conversation_id ───────────────────────────────────────────────
  useEffect(() => {
    if (conversationId) localStorage.setItem(CONVERSATION_ID_KEY, conversationId.toString());
  }, [conversationId]);

  // ── UI actions ────────────────────────────────────────────────────────────
  const toggleChat = () => setIsOpen(o => !o);
  const toggleMaximize = () => setIsMaximized(m => !m);
  const refreshChat = () => {
    setMessages([]);
    setConversationId(null);
    localStorage.removeItem(CONVERSATION_ID_KEY);
  };

  // ── Send a suggested question chip ──────────────────────────────────────
  const sendSuggestedQuestion = (question: string) => {
    // Strip the emoji prefix for the actual message sent
    const clean = question.replace(/^[^\w]+/, '').trim();
    setInput(clean);
    // Use a tiny timeout so state flushes before sendMessage reads `input`
    setTimeout(() => {
      setInput('');
      setLoading(true);
      const userMsg: ChatMessage = { role: 'user', content: clean, timestamp: new Date().toISOString() };
      setMessages(prev => [...prev, userMsg]);
      chatApi({ prompt: clean, session_id: sessionId ?? undefined, interface_type: 'web', conversation_id: conversationId ?? undefined })
        .then((res) => {
          const responseText = res.response || 'No response received';
          if (typeof res.conversation_id === 'number') setConversationId(res.conversation_id);
          setMessages(prev => [...prev, { role: 'assistant', content: responseText, timestamp: new Date().toISOString(), message_id: res.message_id ?? undefined }]);
        })
        .catch(() => {
          setMessages(prev => [...prev, { role: 'assistant', content: '⚠️ Something went wrong. Please try again.', timestamp: new Date().toISOString() }]);
        })
        .finally(() => setLoading(false));
    }, 50);
  };

  const copyToClipboard = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      showToast('Copied to clipboard!', 'success');
    } catch {
      showToast('Copy failed — please select the text manually.', 'error');
    }
  };

  // ── Send message ──────────────────────────────────────────────────────────
  const sendMessage = async () => {
    const prompt = input.trim();
    if (!prompt) return;
    if (prompt.length > MAX_INPUT_LENGTH) {
      showToast(`Message too long (max ${MAX_INPUT_LENGTH} characters)`, 'error');
      return;
    }
    // Rate limit guard
    if (rateLimitReset && Date.now() < rateLimitReset) {
      const secs = Math.ceil((rateLimitReset - Date.now()) / 1000);
      showToast(`Rate limited — please wait ${secs}s before sending again.`, 'warning');
      return;
    }

    setLoading(true);
    const userMsg: ChatMessage = { role: 'user', content: prompt, timestamp: new Date().toISOString() };
    setMessages(prev => [...prev, userMsg]);
    setInput('');

    try {
      const res: ChatResponse = await chatApi({
        prompt,
        session_id: sessionId ?? undefined,
        interface_type: 'web',
        conversation_id: conversationId ?? undefined,
      });

      const responseText = res.response || (res as any).data?.response || 'No response received';
      if (typeof res.conversation_id === 'number') setConversationId(res.conversation_id);

      setMessages(prev => [...prev, {
        role: 'assistant',
        content: responseText,
        timestamp: new Date().toISOString(),
        message_id: res.message_id ?? undefined,
      }]);

    } catch (e: any) {
      let errorText = 'Sorry, I encountered an error. Please try again.';

      if (e.response?.status === 429) {
        // Rate limited — show countdown
        const retryAfter = parseInt(e.response.headers?.['retry-after'] || '60', 10);
        setRateLimitReset(Date.now() + retryAfter * 1000);
        errorText = `⏱️ You've sent too many messages. Please wait ${retryAfter} seconds before trying again.`;
        showToast(`Rate limited — ${retryAfter}s cooldown started.`, 'warning');
      } else if (e.code === 'ERR_NETWORK' || e.message?.includes('Network Error')) {
        errorText = '⚠️ Cannot reach the server. Check your connection or try again shortly.';
      } else if (e.response?.status === 404) {
        errorText = '⚠️ API endpoint not found. Please contact support.';
      } else if (e.response?.status === 500) {
        errorText = '⚠️ The server encountered an error. Our team has been notified.';
      } else if (e.response?.data?.detail) {
        errorText = `⚠️ ${e.response.data.detail}`;
      }

      setMessages(prev => [...prev, { role: 'assistant', content: errorText, timestamp: new Date().toISOString() }]);
    } finally {
      setLoading(false);
    }
  };

  // ── Thumbs / Feedback ─────────────────────────────────────────────────────
  const handleThumbsClick = async (messageIdx: number, type: 'up' | 'down') => {
    const animKey = `${messageIdx}-${type}`;
    setAnimatingThumb(animKey);
    setTimeout(() => setAnimatingThumb(null), 500);

    const newState = new Map(feedbackState);
    newState.set(messageIdx, newState.get(messageIdx) === type ? null : type);
    setFeedbackState(newState);

    const message = messages[messageIdx];
    if (message?.message_id && sessionId) {
      try {
        await submitReaction({ message_id: message.message_id, reaction_type: type === 'up' ? 'thumbs_up' : 'thumbs_down', session_id: sessionId });
        showToast('Thanks for your feedback!', 'success');
      } catch {
        showToast('Could not save feedback. Please try again.', 'error');
      }
    }
  };

  const handleRegenerate = async (messageIdx: number) => {
    if (messageIdx <= 0) return;
    const prevUser = messages[messageIdx - 1];
    if (!prevUser || prevUser.role !== 'user') return;
    setLoading(true);
    try {
      const res: ChatResponse = await chatApi({ prompt: prevUser.content, session_id: sessionId ?? undefined, interface_type: 'web', conversation_id: conversationId ?? undefined });
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: res.response || 'No response',
        timestamp: new Date().toISOString(),
        message_id: res.message_id ?? undefined,
      }]);
    } catch { showToast('Regenerate failed. Please try again.', 'error'); }
    finally { setLoading(false); }
  };

  const openFeedbackModal = (idx: number) => { setSelectedMessageForFeedback(idx); setShowFeedbackModal(true); };

  const handleFeedbackSubmit = async (feedbackData: FeedbackData) => {
    try {
      await submitDetailedFeedback(feedbackData);
      showToast('Feedback submitted — thank you!', 'success');
    } catch {
      showToast('Could not submit feedback. Please try again.', 'error');
      throw new Error('feedback failed');
    }
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  };

  // ── Char counter styling ──────────────────────────────────────────────────
  const inputLength = input.length;
  const isNearLimit = inputLength >= WARN_INPUT_LENGTH;
  const isAtLimit = inputLength >= MAX_INPUT_LENGTH;
  const isRateLimited = !!(rateLimitReset && Date.now() < rateLimitReset);

  // ── Toast colour ──────────────────────────────────────────────────────────
  const toastGradient = toast?.type === 'success'
    ? 'linear-gradient(135deg, #10b981, #059669)'
    : toast?.type === 'warning'
    ? 'linear-gradient(135deg, #f59e0b, #d97706)'
    : 'linear-gradient(135deg, #ef4444, #dc2626)';

  const toastIcon = toast?.type === 'success' ? 'check-circle' : toast?.type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle';

  // ─────────────────────────────────────────────────────────────────────────
  return (
    <ChatRoot>
      <Container isOpen={isOpen} isMaximized={isMaximized}>
        <Header>
          <HeaderContent>
            <HeaderImage>
              <img src="images/ai-avatar.png" alt="Assistant Avatar" />
            </HeaderImage>
            <HeaderText>
              <h4>Campus Assistant</h4>
              <p>How can I help you today?</p>
            </HeaderText>
          </HeaderContent>
          <HeaderControls>
            <HeaderButton onClick={refreshChat} title="New conversation">
              <i className="fas fa-redo-alt"></i>
            </HeaderButton>
            <HeaderButton onClick={toggleMaximize} title={isMaximized ? 'Minimize' : 'Maximize'}>
              <i className={`fas fa-${isMaximized ? 'compress-alt' : 'expand-alt'}`}></i>
            </HeaderButton>
            <HeaderButton onClick={() => setIsOpen(false)} title="Close">
              <i className="fas fa-minus"></i>
            </HeaderButton>
          </HeaderControls>
        </Header>

        <MessagesContainer ref={messagesContainerRef} isMaximized={isMaximized}>
          {messages.length === 0 && (
            <>
              <Message isUser={false}>
                👋 Hi! I'm your MMU Chatbot ask me  anything about MMU.
              </Message>
              {/* ── Suggested question chips ── */}
              <div style={{
                display: 'flex', flexWrap: 'wrap', gap: '8px',
                padding: '4px 12px 12px', justifyContent: 'center',
              }}>
                {SUGGESTED_QUESTIONS.map((q) => (
                  <SuggestedQuestionBtn
                    key={q}
                    onClick={() => sendSuggestedQuestion(q)}
                    disabled={loading}
                  >
                    {q}
                  </SuggestedQuestionBtn>
                ))}
              </div>
            </>
          )}

          {messages.map((m, idx) => (
            <Message key={idx} isUser={m.role === 'user'}>
              {m.role === 'assistant' ? (
                /* ── Markdown rendering for bot replies ── */
                <div className="bot-markdown" style={{ lineHeight: 1.6 }}>
                  <ReactMarkdown remarkPlugins={[remarkGfm]}>{m.content}</ReactMarkdown>
                </div>
              ) : (
                m.content
              )}
              {m.role === 'assistant' && (
                <MessageFeedbackControls>
                  <FeedbackButton active={feedbackState.get(idx) === 'up'} onClick={() => handleThumbsClick(idx, 'up')} title="Helpful"
                    style={animatingThumb === `${idx}-up` ? { animation: 'thumbPulse 0.4s ease' } : {}}>
                    <i className="fas fa-thumbs-up"></i>
                  </FeedbackButton>
                  <FeedbackButton active={feedbackState.get(idx) === 'down'} onClick={() => handleThumbsClick(idx, 'down')} title="Not helpful"
                    style={animatingThumb === `${idx}-down` ? { animation: 'thumbPulse 0.4s ease' } : {}}>
                    <i className="fas fa-thumbs-down"></i>
                  </FeedbackButton>
                  <FeedbackButton onClick={() => handleRegenerate(idx)} title="Regenerate response">
                    <i className="fas fa-redo"></i>
                  </FeedbackButton>
                  <FeedbackButton onClick={() => openFeedbackModal(idx)} title="Write feedback">
                    <i className="fas fa-comment-dots"></i>
                  </FeedbackButton>
                  <FeedbackButton onClick={() => copyToClipboard(m.content)} title="Copy to clipboard">
                    <i className="fas fa-copy"></i>
                  </FeedbackButton>
                </MessageFeedbackControls>
              )}
            </Message>
          ))}

          {loading && (
            <Message isUser={false}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <i className="fas fa-spinner fa-spin"></i>
                Thinking…
              </div>
            </Message>
          )}
        </MessagesContainer>

        <Footer>
          <div style={{ width: '100%' }}>
            <Input
              value={input}
              onChange={(e) => setInput(e.target.value.slice(0, MAX_INPUT_LENGTH))}
              onKeyDown={onKeyDown}
              placeholder={
                isRateLimited
                  ? `Rate limited — please wait…`
                  : loading
                  ? 'Waiting for response…'
                  : 'Type your message…'
              }
              disabled={loading || isRateLimited}
              style={isAtLimit ? { borderColor: '#ef4444' } : isNearLimit ? { borderColor: '#f59e0b' } : {}}
            />

            {/* Character counter — only shown when near limit */}
            {isNearLimit && (
              <div style={{ textAlign: 'right', fontSize: '0.72rem', marginTop: 2, color: isAtLimit ? '#ef4444' : '#f59e0b', fontWeight: 500 }}>
                {inputLength} / {MAX_INPUT_LENGTH}
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '8px', gap: '10px' }}>
              <button
                onClick={() => setShowEscalateForm(true)}
                style={{
                  background: 'rgba(255,255,255,0.15)', backdropFilter: 'blur(10px)',
                  border: '1px solid rgba(255,255,255,0.25)', color: 'white', fontSize: '0.8125rem',
                  cursor: 'pointer', padding: '6px 12px', borderRadius: '20px',
                  transition: 'all 0.3s cubic-bezier(0.4,0,0.2,1)', display: 'flex',
                  alignItems: 'center', gap: '6px', whiteSpace: 'nowrap', fontWeight: 500
                }}
                onMouseOver={(e) => { e.currentTarget.style.backgroundColor = 'rgba(255,255,255,0.25)'; e.currentTarget.style.transform = 'translateY(-2px)'; }}
                onMouseOut={(e) => { e.currentTarget.style.backgroundColor = 'rgba(255,255,255,0.15)'; e.currentTarget.style.transform = 'none'; }}
              >
                <i className="fas fa-life-ring" style={{ fontSize: '1em' }}></i>
                <span>Need help? Contact us</span>
              </button>
              <SendButton onClick={sendMessage} disabled={loading || isRateLimited || isAtLimit}>
                {loading ? <i className="fas fa-spinner fa-spin"></i> : <i className="fas fa-paper-plane"></i>}
              </SendButton>
            </div>
          </div>
        </Footer>
      </Container>

      <ToggleButton onClick={toggleChat}>
        <img src="/images/chatbox-icon.svg" alt="Chat Icon" />
        <OnlineIndicator />
      </ToggleButton>

      <EscalateForm
        isVisible={showEscalateForm}
        onClose={() => setShowEscalateForm(false)}
        sessionId={sessionId}
        conversationId={conversationId}
        onToast={showToast}
      />

      <FeedbackModal
        isVisible={showFeedbackModal}
        onClose={() => setShowFeedbackModal(false)}
        messageId={selectedMessageForFeedback !== null ? messages[selectedMessageForFeedback]?.message_id ?? null : null}
        conversationId={conversationId}
        sessionId={sessionId}
        onSubmit={handleFeedbackSubmit}
      />

      {/* Toast notification */}
      {toast && (
        <div style={{
          position: 'fixed', bottom: '100px', left: '50%', transform: 'translateX(-50%)',
          padding: '10px 20px', borderRadius: '8px', color: '#fff', fontSize: '0.85rem',
          fontWeight: 500, zIndex: 100000, animation: 'toastSlideUp 0.3s ease',
          boxShadow: '0 4px 20px rgba(0,0,0,0.3)', background: toastGradient,
          display: 'flex', alignItems: 'center', gap: 8, whiteSpace: 'nowrap',
        }}>
          <i className={`fas fa-${toastIcon}`}></i>
          {toast.message}
        </div>
      )}

      <style>{`
        @keyframes thumbPulse { 0%{transform:scale(1)} 30%{transform:scale(1.4)} 60%{transform:scale(0.9)} 100%{transform:scale(1)} }
        @keyframes toastSlideUp { from{opacity:0;transform:translateX(-50%) translateY(20px)} to{opacity:1;transform:translateX(-50%) translateY(0)} }
        .bot-markdown p { margin: 0 0 0.5em 0; }
        .bot-markdown { text-align: left; width: 100%; }
        .bot-markdown ul, .bot-markdown ol { margin: 0.35em 0 0.65em 0; padding: 0 0 0 1.35em; list-style-position: outside; text-align: left; }
        .bot-markdown li { margin-bottom: 0.25em; text-align: left; }
        .bot-markdown p { text-align: left; }
        .bot-markdown strong { font-weight: 700; }
        .bot-markdown h1, .bot-markdown h2, .bot-markdown h3 { margin: 0.4em 0 0.2em; font-size: 1.05em; }
        .bot-markdown code { background: rgba(0,0,0,0.1); border-radius: 3px; padding: 1px 4px; font-size: 0.9em; }
        .bot-markdown pre { background: rgba(0,0,0,0.15); border-radius: 6px; padding: 8px 12px; overflow-x: auto; }
        .bot-markdown a { color: inherit; text-decoration: underline; }
      `}</style>
    </ChatRoot>
  );
}
