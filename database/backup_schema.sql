-- ========================================
-- CAMPUS AI CHATBOT DATABASE SCHEMA - ENTITY-BASED VERSION
-- Database: campus_ai_db
-- DBMS: MySQL 8.0+
-- Purpose: RAG-powered chatbot with expandable entity-based knowledge base
-- Date: 2026-02-17
-- Integrated: Entity hierarchy + RAG optimization + Chatbot functionality
-- ========================================

CREATE DATABASE IF NOT EXISTS campus_ai_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE campus_ai_db;

-- ========================================
-- SECTION 1: SESSION AND USER MANAGEMENT
-- ========================================

CREATE TABLE web_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    device_type VARCHAR(50),
    device_model VARCHAR(100),
    device_brand VARCHAR(50),
    os_name VARCHAR(50),
    os_version VARCHAR(50),
    browser_name VARCHAR(50),
    browser_version VARCHAR(50),
    screen_resolution VARCHAR(20),
    interface_type VARCHAR(10) NOT NULL DEFAULT 'web',
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP NULL,
    duration_seconds INT DEFAULT 0,
    ip_address VARCHAR(45),
    location VARCHAR(255),
    status ENUM('active', 'ended', 'expired', 'timeout') DEFAULT 'active',
    total_messages_sent INT DEFAULT 0,
    total_messages_received INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_session_token (session_token),
    INDEX idx_status (status),
    INDEX idx_start_time (start_time),
    INDEX idx_ip_location (ip_address, location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Anonymous web user sessions with device tracking';

-- ========================================
-- SECTION 2: ADMIN MANAGEMENT
-- ========================================

CREATE TABLE admins (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'moderator', 'viewer', 'content_manager') DEFAULT 'admin',
    phone_number VARCHAR(20),
    profile_image VARCHAR(500) COMMENT 'Path to admin profile image',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Admin users with role-based access';

CREATE TABLE admin_password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    token VARCHAR(128) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id),
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at),
    INDEX idx_used_at (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Password reset tokens for admin users';

CREATE TABLE admin_permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    module VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_permission_name (permission_name),
    INDEX idx_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Available permissions for admin roles';

CREATE TABLE admin_role_permissions (
    role_permission_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    permission_id INT NOT NULL,
    can_create BOOLEAN DEFAULT FALSE,
    can_read BOOLEAN DEFAULT TRUE,
    can_update BOOLEAN DEFAULT FALSE,
    can_delete BOOLEAN DEFAULT FALSE,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES admin_permissions(permission_id) ON DELETE CASCADE,
    UNIQUE KEY uk_admin_permission (admin_id, permission_id),
    INDEX idx_admin_id (admin_id),
    INDEX idx_permission_id (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Junction table for admin permissions with CRUD flags';

CREATE TABLE admin_activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50),
    description TEXT,
    affected_records JSON,
    ip_address VARCHAR(45),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id),
    INDEX idx_action (action),
    INDEX idx_module (module),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit log of admin actions';

-- ========================================
-- SECTION 3: ENTITY-BASED KNOWLEDGE BASE
-- ========================================

-- Extensible registry of entity types (university, faculty, department, program, staff, etc.)
CREATE TABLE entity_types (
    type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) UNIQUE NOT NULL COMMENT 'machine name: university, faculty, department, program, staff, admission, facility, event, etc.',
    type_label VARCHAR(255) NOT NULL COMMENT 'Human-readable label',
    icon VARCHAR(100) DEFAULT 'fa-cube' COMMENT 'FontAwesome icon class for admin UI',
    description TEXT,
    parent_type_id INT DEFAULT NULL COMMENT 'Optional parent type for type hierarchy (e.g. department is child type of faculty)',
    field_schema JSON COMMENT 'JSON schema defining expected structured_data fields for this type',
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_type_id) REFERENCES entity_types(type_id) ON DELETE SET NULL,
    INDEX idx_type_name (type_name),
    INDEX idx_is_active (is_active),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Extensible registry of entity types - unlimited expansion';

-- Core entity store: one row per entity regardless of type
CREATE TABLE university_entities (
    entity_id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type_id INT NOT NULL COMMENT 'FK to entity_types',
    entity_code VARCHAR(50) DEFAULT NULL COMMENT 'Short code like U001, F001, D001, P001',
    university_id INT DEFAULT NULL COMMENT 'FK to self - which university this entity belongs to (NULL for university entity itself)',
    parent_entity_id INT DEFAULT NULL COMMENT 'FK to self - direct parent entity for hierarchy (Faculty->Dept->Program)',
    name VARCHAR(500) NOT NULL,
    short_name VARCHAR(100) DEFAULT NULL,
    description TEXT,
    structured_data JSON COMMENT 'Flexible fields: dean, founded_year, tuition_fee, duration_years, location, achievements, etc. — schema defined by entity_types.field_schema',
    metadata JSON COMMENT 'Source tracking: source_type, source_url, source_date, language, translation_summary, version, last_updated, conflicts[], related_entities[]',
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_type_id) REFERENCES entity_types(type_id) ON DELETE RESTRICT,
    FOREIGN KEY (university_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    FOREIGN KEY (parent_entity_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_entity_type_id (entity_type_id),
    INDEX idx_entity_code (entity_code),
    INDEX idx_university_id (university_id),
    INDEX idx_parent_entity_id (parent_entity_id),
    INDEX idx_is_active (is_active),
    INDEX idx_display_order (display_order),
    INDEX idx_name (name(100)),
    FULLTEXT INDEX ft_entity_search (name, short_name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Core entity store — one row per entity with unlimited nesting via parent_entity_id';

-- Explicit relationships beyond parent-child hierarchy
CREATE TABLE entity_relationships (
    relationship_id INT AUTO_INCREMENT PRIMARY KEY,
    source_entity_id INT NOT NULL,
    target_entity_id INT NOT NULL,
    relationship_type VARCHAR(100) NOT NULL COMMENT 'e.g. faculty_of, head_of, offered_by, located_at, prerequisite_of',
    description TEXT,
    metadata JSON COMMENT 'Additional relationship attributes',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (source_entity_id) REFERENCES university_entities(entity_id) ON DELETE CASCADE,
    FOREIGN KEY (target_entity_id) REFERENCES university_entities(entity_id) ON DELETE CASCADE,
    UNIQUE KEY uk_relationship (source_entity_id, target_entity_id, relationship_type),
    INDEX idx_source_entity (source_entity_id),
    INDEX idx_target_entity (target_entity_id),
    INDEX idx_relationship_type (relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Explicit relationships between entities beyond parent-child';

-- RAG-ready knowledge chunks per entity (1-N chunks per entity)
CREATE TABLE entity_knowledge_chunks (
    chunk_id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL COMMENT 'FK to university_entities',
    chunk_index INT NOT NULL DEFAULT 0 COMMENT 'Order within entity (0, 1, 2)',
    title VARCHAR(500) NOT NULL COMMENT 'Chunk title for display, e.g. "Faculty of Science Overview"',
    content TEXT NOT NULL COMMENT '1-3 descriptive paragraphs for RAG embeddings',
    chunk_metadata JSON COMMENT '{"entity_type":"faculty","entity_id":"F001","university_id":"U001","chunk_index":0}',
    token_count INT DEFAULT NULL,
    char_count INT DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES university_entities(entity_id) ON DELETE CASCADE,
    UNIQUE KEY uk_entity_chunk (entity_id, chunk_index),
    INDEX idx_entity_id (entity_id),
    INDEX idx_chunk_index (chunk_index),
    INDEX idx_is_active (is_active),
    FULLTEXT INDEX ft_chunk_content (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='RAG-ready knowledge chunks per entity for semantic search';

-- Entity change history / audit trail
CREATE TABLE entity_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    action ENUM('created', 'updated', 'deleted', 'restored', 'archived') NOT NULL,
    version INT NOT NULL DEFAULT 1,
    old_data JSON COMMENT 'Snapshot of previous state',
    new_data JSON COMMENT 'Snapshot of new state',
    changed_by INT,
    change_reason TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES university_entities(entity_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_entity_id (entity_id),
    INDEX idx_action (action),
    INDEX idx_version (version),
    INDEX idx_created_at (created_at),
    INDEX idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for entity changes';

-- ========================================
-- SECTION 4: UNIFIED CAMPUS KNOWLEDGE
-- ========================================

CREATE TABLE campus_knowledge_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL COMMENT 'Program, Fee Structure, Admission, Cut-off Points, Scholarship, etc.',
    subcategory VARCHAR(150) DEFAULT NULL,
    program_code VARCHAR(30) DEFAULT NULL,
    academic_year VARCHAR(20) DEFAULT NULL,
    semester ENUM('1', '2', 'both', 'annual') DEFAULT NULL,
    title VARCHAR(300) NOT NULL,
    short_description TEXT,
    full_content LONGTEXT NOT NULL,
    duration_years DECIMAL(4,1) DEFAULT NULL,
    tuition_per_semester DECIMAL(12,2) DEFAULT NULL,
    functional_fees DECIMAL(12,2) DEFAULT NULL,
    min_cutoff_points DECIMAL(5,1) DEFAULT NULL,
    max_intake INT DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_featured BOOLEAN DEFAULT FALSE,
    display_order INT DEFAULT 0,
    visibility ENUM('public', 'internal') DEFAULT 'public',
    entity_id INT DEFAULT NULL COMMENT 'Link to university_entities for hierarchy',
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_category_subcategory (category, subcategory),
    INDEX idx_program_code (program_code),
    INDEX idx_academic_year (academic_year),
    INDEX idx_title (title(100)),
    INDEX idx_entity_id (entity_id),
    FULLTEXT INDEX ft_search (title, short_description, full_content),
    INDEX idx_active_year_cat (is_active, academic_year, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Structured campus data with entity integration';

-- ========================================
-- SECTION 5: CHAT AND CONVERSATION MANAGEMENT
-- ========================================

CREATE TABLE conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    conversation_title VARCHAR(255),
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    status ENUM('active', 'completed', 'abandoned') DEFAULT 'active',
    total_messages INT DEFAULT 0,
    user_messages_count INT DEFAULT 0,
    bot_messages_count INT DEFAULT 0,
    avg_response_time_ms FLOAT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES web_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User conversation sessions';

CREATE TABLE chat_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    session_id INT NOT NULL,
    sender_type ENUM('user', 'bot', 'system') NOT NULL,
    user_message TEXT,
    bot_response TEXT,
    intent_classification VARCHAR(64) DEFAULT 'general_campus',
    response_type VARCHAR(64) DEFAULT 'rag_based',
    model_used VARCHAR(100),
    response_time_ms FLOAT DEFAULT 0,
    context_retrieved BOOLEAN DEFAULT FALSE,
    retrieval_doc_count INT DEFAULT 0,
    confidence_score FLOAT DEFAULT 0,
    was_helpful BOOLEAN,
    parent_message_id INT,
    user_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    bot_timestamp TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES web_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_message_id) REFERENCES chat_messages(message_id) ON DELETE SET NULL,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_session_id (session_id),
    INDEX idx_sender_type (sender_type),
    INDEX idx_intent_classification (intent_classification),
    INDEX idx_response_type (response_type),
    INDEX idx_model_used (model_used),
    INDEX idx_created_at (created_at),
    INDEX idx_confidence_score (confidence_score),
    INDEX idx_messages_conversation_timestamp (conversation_id, created_at),
    FULLTEXT INDEX ft_user_message (user_message),
    FULLTEXT INDEX ft_bot_response (bot_response)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual chat messages with RAG metadata';

-- Links messages to retrieved knowledge chunks
CREATE TABLE message_context (
    context_id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    chunk_id INT COMMENT 'Link to entity_knowledge_chunks',
    entity_id INT COMMENT 'Link to university_entities',
    retrieved_context TEXT,
    similarity_score FLOAT DEFAULT 0,
    source_document VARCHAR(500),
    source_type VARCHAR(50),
    rank_position INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES chat_messages(message_id) ON DELETE CASCADE,
    FOREIGN KEY (chunk_id) REFERENCES entity_knowledge_chunks(chunk_id) ON DELETE SET NULL,
    FOREIGN KEY (entity_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    INDEX idx_message_id (message_id),
    INDEX idx_chunk_id (chunk_id),
    INDEX idx_entity_id (entity_id),
    INDEX idx_similarity_score (similarity_score),
    INDEX idx_rank_position (rank_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Links messages to retrieved entity knowledge chunks';

CREATE TABLE conversation_metadata (
    metadata_id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    conversation_summary TEXT,
    topics_discussed JSON,
    sentiment_score INT CHECK (sentiment_score BETWEEN 1 AND 5),
    satisfaction_score INT CHECK (satisfaction_score BETWEEN 1 AND 5),
    issue_resolved BOOLEAN DEFAULT FALSE,
    unresolved_topics JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    UNIQUE KEY uk_conversation_id (conversation_id),
    INDEX idx_sentiment_score (sentiment_score),
    INDEX idx_satisfaction_score (satisfaction_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Conversation-level metadata and analytics';

-- ========================================
-- SECTION 6: FEEDBACK AND RATINGS
-- ========================================

CREATE TABLE feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    conversation_id INT,
    message_id INT,
    rating ENUM('excellent', 'good', 'bad') DEFAULT 'good',
    comment TEXT,
    category ENUM('accuracy', 'speed', 'helpfulness', 'tone', 'relevance', 'completeness') DEFAULT 'helpfulness',
    is_reviewed BOOLEAN DEFAULT FALSE,
    reviewed_by INT,
    admin_notes TEXT,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES web_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(message_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_rating (rating),
    INDEX idx_category (category),
    INDEX idx_is_reviewed (is_reviewed),
    INDEX idx_created_at (created_at),
    INDEX idx_feedback_rating_date (rating, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User feedback on bot performance';

CREATE TABLE message_reactions (
    reaction_id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    session_id INT NOT NULL,
    reaction_type ENUM('thumbs_up', 'thumbs_down', 'helpful', 'not_helpful', 'accurate', 'inaccurate') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES chat_messages(message_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES web_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_message_id (message_id),
    INDEX idx_reaction_type (reaction_type),
    UNIQUE KEY uk_message_session_reaction (message_id, session_id, reaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Quick reactions to bot messages';

-- ========================================
-- SECTION 7: USER QUERIES AND INQUIRIES
-- ========================================

CREATE TABLE user_queries (
    query_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    conversation_id INT,
    message_id INT,
    query_text TEXT NOT NULL,
    query_type ENUM('technical', 'admission', 'general', 'complaint', 'feature_request', 'academic', 'financial') DEFAULT 'general',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('pending', 'in_progress', 'resolved', 'closed', 'escalated') DEFAULT 'pending',
    assigned_to INT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    admin_response TEXT,
    resolved_by INT,
    resolution_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES web_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE SET NULL,
    FOREIGN KEY (message_id) REFERENCES chat_messages(message_id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES admins(admin_id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_query_type (query_type),
    INDEX idx_priority (priority),
    INDEX idx_status (status),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_queries_status_priority (status, priority, submitted_at),
    FULLTEXT INDEX ft_query_text (query_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User inquiries requiring admin attention';

-- ========================================
-- SECTION 8: WEB SCRAPING MANAGEMENT
-- ========================================

CREATE TABLE scraping_sources (
    source_id INT AUTO_INCREMENT PRIMARY KEY,
    source_name VARCHAR(255) NOT NULL,
    base_url VARCHAR(1000) NOT NULL,
    scrape_frequency ENUM('hourly', 'daily', 'weekly', 'monthly') DEFAULT 'daily',
    last_scraped TIMESTAMP NULL,
    next_scrape_scheduled TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    scraping_config JSON,
    success_count INT DEFAULT 0,
    failure_count INT DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_source_name (source_name),
    INDEX idx_is_active (is_active),
    INDEX idx_next_scrape_scheduled (next_scrape_scheduled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Web scraping source configuration';

CREATE TABLE scraped_content (
    scraped_id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    entity_id INT DEFAULT NULL COMMENT 'Link to university_entities after processing',
    page_url VARCHAR(1000) NOT NULL,
    page_title VARCHAR(500),
    raw_content LONGTEXT,
    cleaned_content LONGTEXT,
    sections_json JSON DEFAULT NULL COMMENT 'Structured sections [{heading, text}] from scraper',
    search_document TEXT DEFAULT NULL COMMENT 'Rule-built document card for retrieval',
    enrichment_json JSON DEFAULT NULL COMMENT 'LLM enrichment metadata (tags, summary, page_type)',
    enrichment_hash VARCHAR(64) DEFAULT NULL COMMENT 'Hash when enrichment last ran',
    enrichment_status ENUM('pending', 'done', 'failed', 'skipped') DEFAULT 'pending',
    enriched_at TIMESTAMP NULL,
    content_hash VARCHAR(64),
    meta_author VARCHAR(255) DEFAULT NULL COMMENT 'Extracted page author',
    meta_publish_date VARCHAR(100) DEFAULT NULL COMMENT 'Extracted publish date',
    meta_category VARCHAR(255) DEFAULT NULL COMMENT 'Extracted page category',
    status ENUM('new', 'processed', 'indexed', 'failed', 'duplicate', 'updated') DEFAULT 'new',
    error_message TEXT,
    scraped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    indexed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id) REFERENCES scraping_sources(source_id) ON DELETE CASCADE,
    FOREIGN KEY (entity_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    UNIQUE KEY uk_source_page_url (source_id, page_url(500)),
    INDEX idx_source_id (source_id),
    INDEX idx_status (status),
    INDEX idx_content_hash (content_hash),
    INDEX idx_scraped_at (scraped_at),
    INDEX idx_entity_id (entity_id),
    FULLTEXT INDEX ft_cleaned_content (cleaned_content),
    FULLTEXT INDEX ft_scraped_search (page_title, search_document, cleaned_content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Scraped web content with processing status and metadata';

CREATE TABLE scraped_content_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    scraped_id INT NOT NULL,
    page_url VARCHAR(1000) NOT NULL,
    page_title VARCHAR(500),
    cleaned_content LONGTEXT,
    content_hash VARCHAR(64),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scraped_id) REFERENCES scraped_content(scraped_id) ON DELETE CASCADE,
    INDEX idx_scraped_id (scraped_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Version history for scraped content changes';

-- ========================================
-- SECTION 9: AI MODEL MANAGEMENT
-- ========================================

CREATE TABLE ai_models (
    model_id INT AUTO_INCREMENT PRIMARY KEY,
    model_name VARCHAR(100) NOT NULL,
    model_version VARCHAR(50),
    model_type ENUM('local_ollama', 'cloud_api', 'hybrid') DEFAULT 'local_ollama',
    model_path VARCHAR(500),
    model_size_mb INT,
    model_config JSON,
    api_provider VARCHAR(50) DEFAULT NULL COMMENT 'Cloud provider: gemini, openai, anthropic, custom',
    api_endpoint VARCHAR(500) DEFAULT NULL COMMENT 'Cloud API endpoint URL',
    api_key VARCHAR(500) DEFAULT NULL COMMENT 'Cloud API key',
    status ENUM('active', 'inactive', 'testing', 'deprecated') DEFAULT 'inactive',
    is_default BOOLEAN DEFAULT FALSE,
    usage_count INT DEFAULT 0,
    deployed_at TIMESTAMP NULL,
    deployed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (deployed_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_model_name (model_name),
    INDEX idx_status (status),
    INDEX idx_is_default (is_default),
    INDEX idx_model_type (model_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AI model registry and configuration';

CREATE TABLE model_performance_metrics (
    metric_id INT AUTO_INCREMENT PRIMARY KEY,
    model_id INT NOT NULL,
    metric_date DATE NOT NULL,
    total_requests INT DEFAULT 0,
    avg_response_time_ms FLOAT DEFAULT 0,
    avg_similarity_score FLOAT DEFAULT 0,
    successful_responses INT DEFAULT 0,
    failed_responses INT DEFAULT 0,
    hallucination_count INT DEFAULT 0,
    out_of_context_count INT DEFAULT 0,
    refusal_count INT DEFAULT 0,
    user_satisfaction_avg FLOAT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES ai_models(model_id) ON DELETE CASCADE,
    INDEX idx_model_id (model_id),
    INDEX idx_metric_date (metric_date),
    UNIQUE KEY uk_model_date (model_id, metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily performance metrics per AI model';

CREATE TABLE model_training_history (
    training_id INT AUTO_INCREMENT PRIMARY KEY,
    model_id INT NOT NULL,
    training_type ENUM('initial', 'retrain', 'fine_tune', 'update') DEFAULT 'retrain',
    dataset_size INT,
    training_epochs INT,
    training_config JSON,
    training_started TIMESTAMP NULL,
    training_completed TIMESTAMP NULL,
    duration_minutes INT,
    status ENUM('in_progress', 'completed', 'failed') DEFAULT 'in_progress',
    error_log TEXT,
    triggered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES ai_models(model_id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_model_id (model_id),
    INDEX idx_status (status),
    INDEX idx_training_started (training_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Model training and fine-tuning history';

-- ========================================
-- SECTION 10: SEARCH ANALYTICS
-- ========================================

CREATE TABLE search_analytics (
    analytics_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    query_text TEXT NOT NULL,
    query_embedding_model VARCHAR(100),
    documents_found INT DEFAULT 0,
    top_entity_id INT COMMENT 'Top matching entity',
    top_chunk_id INT COMMENT 'Top matching knowledge chunk',
    clicked_entity_id INT COMMENT 'Entity user clicked/explored',
    clicked_chunk_id INT COMMENT 'Chunk user found useful',
    avg_similarity_score FLOAT,
    search_duration_ms FLOAT,
    user_satisfied BOOLEAN,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES web_sessions(session_id) ON DELETE SET NULL,
    FOREIGN KEY (top_entity_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    FOREIGN KEY (top_chunk_id) REFERENCES entity_knowledge_chunks(chunk_id) ON DELETE SET NULL,
    FOREIGN KEY (clicked_entity_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    FOREIGN KEY (clicked_chunk_id) REFERENCES entity_knowledge_chunks(chunk_id) ON DELETE SET NULL,
    INDEX idx_timestamp (timestamp),
    INDEX idx_session_id (session_id),
    INDEX idx_documents_found (documents_found),
    FULLTEXT INDEX ft_query_text (query_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Analytics for search queries and results';

-- ========================================
-- SECTION 11: VECTOR DATABASE INTEGRATION
-- ========================================

CREATE TABLE vector_embeddings (
    embedding_id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT COMMENT 'Link to university_entities',
    chunk_id INT COMMENT 'Link to entity_knowledge_chunks',
    chunk_text TEXT,
    embedding_vector BLOB,
    vector_dimension INT DEFAULT 384,
    embedding_model VARCHAR(100) DEFAULT 'all-MiniLM-L6-v2',
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES university_entities(entity_id) ON DELETE CASCADE,
    FOREIGN KEY (chunk_id) REFERENCES entity_knowledge_chunks(chunk_id) ON DELETE CASCADE,
    INDEX idx_entity_id (entity_id),
    INDEX idx_chunk_id (chunk_id),
    INDEX idx_embedding_model (embedding_model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Vector embeddings for entity knowledge chunks';

CREATE TABLE faiss_index_metadata (
    index_id INT AUTO_INCREMENT PRIMARY KEY,
    index_name VARCHAR(255) NOT NULL,
    total_vectors INT DEFAULT 0,
    vector_dimension INT DEFAULT 384,
    last_rebuild TIMESTAMP NULL,
    faiss_index_path VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_index_name (index_name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FAISS index metadata for vector search';

-- ========================================
-- SECTION 12: ANALYTICS AND REPORTING
-- ========================================

CREATE TABLE analytics_daily (
    analytics_id INT AUTO_INCREMENT PRIMARY KEY,
    analytics_date DATE NOT NULL,
    total_sessions INT DEFAULT 0,
    active_sessions INT DEFAULT 0,
    total_conversations INT DEFAULT 0,
    total_messages INT DEFAULT 0,
    avg_session_duration_min FLOAT DEFAULT 0,
    successful_responses INT DEFAULT 0,
    failed_responses INT DEFAULT 0,
    out_of_scope_queries INT DEFAULT 0,
    sensitive_data_requests INT DEFAULT 0,
    faq_responses INT DEFAULT 0,
    rag_responses INT DEFAULT 0,
    avg_response_time_ms FLOAT DEFAULT 0,
    avg_confidence_score FLOAT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_analytics_date (analytics_date),
    UNIQUE KEY uk_analytics_date (analytics_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily aggregated analytics';

CREATE TABLE usage_metrics (
    metric_id INT AUTO_INCREMENT PRIMARY KEY,
    metric_date DATE NOT NULL,
    device_type VARCHAR(50),
    os_name VARCHAR(50),
    browser_name VARCHAR(50),
    total_sessions INT DEFAULT 0,
    total_conversations INT DEFAULT 0,
    total_messages INT DEFAULT 0,
    avg_messages_per_conversation FLOAT DEFAULT 0,
    avg_response_time_ms FLOAT DEFAULT 0,
    peak_hour INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_date (metric_date),
    INDEX idx_device_type (device_type),
    UNIQUE KEY uk_usage_metrics (metric_date, device_type, browser_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Usage metrics by device and platform';

CREATE TABLE reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    generated_by INT NOT NULL,
    report_name VARCHAR(255) NOT NULL,
    report_type ENUM('user_activity', 'model_performance', 'feedback_summary', 'system_health', 'kb_analytics') DEFAULT 'user_activity',
    start_date DATE,
    end_date DATE,
    filters JSON,
    file_path VARCHAR(500),
    format ENUM('pdf', 'excel', 'csv', 'json') DEFAULT 'pdf',
    status ENUM('generating', 'completed', 'failed') DEFAULT 'generating',
    generated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES admins(admin_id) ON DELETE CASCADE,
    INDEX idx_report_type (report_type),
    INDEX idx_status (status),
    INDEX idx_generated_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Generated reports and exports';

-- ========================================
-- SECTION 13: SYSTEM LOGS
-- ========================================

CREATE TABLE system_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    log_level ENUM('debug', 'info', 'warning', 'error', 'critical') DEFAULT 'info',
    module VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    stack_trace TEXT,
    ip_address VARCHAR(45),
    metadata JSON,
    is_reviewed BOOLEAN DEFAULT FALSE,
    reviewed_by INT,
    reviewed_at TIMESTAMP NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewed_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_log_level (log_level),
    INDEX idx_module (module),
    INDEX idx_timestamp (timestamp),
    INDEX idx_is_reviewed (is_reviewed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System-wide logging';

CREATE TABLE error_logs (
    error_id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT,
    conversation_id INT,
    entity_id INT COMMENT 'Link to university_entities if error relates to an entity',
    error_type VARCHAR(100) NOT NULL,
    error_message TEXT,
    stack_trace TEXT,
    is_resolved BOOLEAN DEFAULT FALSE,
    resolved_by INT,
    resolution_notes TEXT,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES chat_messages(message_id) ON DELETE SET NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE SET NULL,
    FOREIGN KEY (entity_id) REFERENCES university_entities(entity_id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_error_type (error_type),
    INDEX idx_is_resolved (is_resolved),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Application error tracking';

-- ========================================
-- SECTION 14: INSERT DEFAULT DATA
-- ========================================

-- Default admin user (CHANGE PASSWORD IN PRODUCTION!)
INSERT INTO admins (username, email, password_hash, full_name, role, is_active)
VALUES ('admin', 'admin@university.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'super_admin', TRUE);

-- Default admin permissions
INSERT INTO admin_permissions (permission_name, description, module) VALUES
('view_dashboard', 'View main dashboard', 'dashboard'),
('manage_entities', 'Manage university entities', 'entities'),
('manage_entity_types', 'Manage entity type registry', 'entities'),
('manage_chunks', 'Manage RAG knowledge chunks', 'entities'),
('view_analytics', 'View analytics and reports', 'analytics'),
('manage_ai_model', 'Manage AI models', 'ai_model'),
('view_feedback', 'View user feedback', 'feedback'),
('view_queries', 'View user queries', 'queries'),
('manage_scraping', 'Manage web scraping', 'scraping'),
('system_settings', 'Manage system settings', 'system');

-- Default entity types
INSERT INTO entity_types (type_name, type_label, icon, description, parent_type_id, field_schema, display_order) VALUES
('university', 'University', 'fa-university', 'A university or higher education institution', NULL,
 '{"fields":[{"name":"location","type":"text","label":"Location"},{"name":"founded_year","type":"number","label":"Founded Year"},{"name":"chancellor","type":"text","label":"Chancellor"},{"name":"vice_chancellor","type":"text","label":"Vice Chancellor"},{"name":"student_count","type":"number","label":"Student Count"},{"name":"type","type":"text","label":"Type (Private/Public)"},{"name":"accredited_status","type":"text","label":"Accreditation Status"},{"name":"website_url","type":"url","label":"Website URL"},{"name":"contact_email","type":"email","label":"Contact Email"},{"name":"contact_phone","type":"text","label":"Contact Phone"},{"name":"address","type":"text","label":"Address"},{"name":"mission","type":"textarea","label":"Mission"},{"name":"vision","type":"textarea","label":"Vision"}]}',
 1),
('faculty', 'Faculty', 'fa-building-columns', 'A faculty within a university', NULL,
 '{"fields":[{"name":"code","type":"text","label":"Faculty Code"},{"name":"dean","type":"text","label":"Dean"},{"name":"founded_year","type":"number","label":"Founded Year"},{"name":"location","type":"text","label":"Location"},{"name":"achievements","type":"json_array","label":"Achievements"},{"name":"history","type":"textarea","label":"History"}]}',
 2),
('department', 'Department', 'fa-sitemap', 'A department within a faculty', NULL,
 '{"fields":[{"name":"code","type":"text","label":"Department Code"},{"name":"head","type":"text","label":"Head of Department"},{"name":"founded_year","type":"number","label":"Founded Year"},{"name":"location","type":"text","label":"Location"}]}',
 3),
('program', 'Program', 'fa-graduation-cap', 'An academic program or course', NULL,
 '{"fields":[{"name":"code","type":"text","label":"Program Code"},{"name":"level","type":"select","label":"Level","options":["Certificate","Diploma","Bachelor","Master","PhD","Postgraduate Diploma"]},{"name":"duration_years","type":"number","label":"Duration (Years)"},{"name":"tuition_fee","type":"number","label":"Tuition Fee"},{"name":"admission_requirements","type":"textarea","label":"Admission Requirements"},{"name":"career_opportunities","type":"textarea","label":"Career Opportunities"},{"name":"is_active","type":"boolean","label":"Currently Active"}]}',
 4),
('staff', 'Staff', 'fa-user-tie', 'Academic or administrative staff', NULL,
 '{"fields":[{"name":"title","type":"text","label":"Title (Prof/Dr/Mr/Ms)"},{"name":"position","type":"text","label":"Position"},{"name":"email","type":"email","label":"Email"},{"name":"phone","type":"text","label":"Phone"},{"name":"specialization","type":"text","label":"Specialization"},{"name":"qualifications","type":"textarea","label":"Qualifications"},{"name":"publications","type":"json_array","label":"Notable Publications"}]}',
 5),
('admission', 'Admission', 'fa-door-open', 'Admission cycle or intake information', NULL,
 '{"fields":[{"name":"intake_year","type":"text","label":"Intake Year"},{"name":"deadline_date","type":"date","label":"Application Deadline"},{"name":"requirements","type":"textarea","label":"Requirements"},{"name":"fee_amount","type":"number","label":"Application Fee"},{"name":"intake_period","type":"text","label":"Intake Period (Jan/May/Aug)"},{"name":"is_open","type":"boolean","label":"Currently Open"}]}',
 6),
('facility', 'Facility', 'fa-building', 'Campus facility (lab, library, hostel, etc.)', NULL,
 '{"fields":[{"name":"type","type":"text","label":"Facility Type"},{"name":"location","type":"text","label":"Location"},{"name":"operating_hours","type":"text","label":"Operating Hours"},{"name":"capacity","type":"number","label":"Capacity"},{"name":"contact_info","type":"text","label":"Contact Info"}]}',
 7),
('event', 'Event', 'fa-calendar-days', 'University event, seminar, or activity', NULL,
 '{"fields":[{"name":"event_type","type":"text","label":"Event Type"},{"name":"start_date","type":"date","label":"Start Date"},{"name":"end_date","type":"date","label":"End Date"},{"name":"location","type":"text","label":"Location"},{"name":"organizer","type":"text","label":"Organizer"},{"name":"registration_url","type":"url","label":"Registration URL"},{"name":"is_free","type":"boolean","label":"Free Event"}]}',
 8);

-- Default AI model
INSERT INTO ai_models (model_name, model_version, model_type, status, is_default) VALUES
('llama3.2:latest', '3.2', 'local_ollama', 'active', TRUE);

-- ========================================
-- SECTION 15: USEFUL VIEWS
-- ========================================

-- Active conversations with session info
CREATE OR REPLACE VIEW v_active_conversations AS
SELECT
    c.conversation_id,
    c.conversation_title,
    c.started_at,
    c.total_messages,
    s.session_token,
    s.device_type,
    s.browser_name,
    s.ip_address,
    s.location
FROM conversations c
INNER JOIN web_sessions s ON c.session_id = s.session_id
WHERE c.status = 'active';

-- Daily metrics summary
CREATE OR REPLACE VIEW v_daily_metrics_summary AS
SELECT
    analytics_date,
    total_sessions,
    total_conversations,
    total_messages,
    successful_responses,
    failed_responses,
    ROUND((successful_responses / NULLIF(total_messages, 0) * 100), 2) AS success_rate,
    avg_session_duration_min,
    avg_response_time_ms,
    avg_confidence_score
FROM analytics_daily
ORDER BY analytics_date DESC;

-- Pending user queries
CREATE OR REPLACE VIEW v_pending_queries AS
SELECT
    q.query_id,
    q.query_text,
    q.query_type,
    q.priority,
    q.submitted_at,
    COALESCE(a.full_name, 'Unassigned') AS assigned_to_name,
    s.session_token,
    s.ip_address
FROM user_queries q
INNER JOIN web_sessions s ON q.session_id = s.session_id
LEFT JOIN admins a ON q.assigned_to = a.admin_id
WHERE q.status IN ('pending', 'in_progress')
ORDER BY q.priority DESC, q.submitted_at ASC;

-- Model performance summary
CREATE OR REPLACE VIEW v_model_performance_summary AS
SELECT
    m.model_name,
    m.status,
    COUNT(DISTINCT mp.metric_date) AS days_tracked,
    AVG(mp.avg_response_time_ms) AS avg_response_time,
    AVG(mp.user_satisfaction_avg) AS avg_satisfaction,
    SUM(mp.total_requests) AS total_requests,
    SUM(mp.successful_responses) AS successful_responses,
    ROUND((SUM(mp.successful_responses) / NULLIF(SUM(mp.total_requests), 0) * 100), 2) AS success_rate
FROM ai_models m
LEFT JOIN model_performance_metrics mp ON m.model_id = mp.model_id
GROUP BY m.model_id, m.model_name, m.status;

-- Entity hierarchy view (tree visualization)
CREATE OR REPLACE VIEW v_entity_hierarchy AS
SELECT
    e.entity_id,
    e.entity_code,
    e.name AS entity_name,
    e.short_name,
    et.type_name AS entity_type,
    et.type_label,
    et.icon,
    p.entity_id AS parent_id,
    p.name AS parent_name,
    pt.type_name AS parent_type,
    u.entity_id AS university_entity_id,
    u.name AS university_name,
    e.is_active,
    e.display_order,
    (SELECT COUNT(*) FROM university_entities c WHERE c.parent_entity_id = e.entity_id) AS children_count,
    (SELECT COUNT(*) FROM entity_knowledge_chunks ck WHERE ck.entity_id = e.entity_id AND ck.is_active = TRUE) AS chunk_count
FROM university_entities e
INNER JOIN entity_types et ON e.entity_type_id = et.type_id
LEFT JOIN university_entities p ON e.parent_entity_id = p.entity_id
LEFT JOIN entity_types pt ON p.entity_type_id = pt.type_id
LEFT JOIN university_entities u ON e.university_id = u.entity_id;

-- Entity stats view
CREATE OR REPLACE VIEW v_entity_stats AS
SELECT
    et.type_name,
    et.type_label,
    et.icon,
    COUNT(DISTINCT e.entity_id) AS entity_count,
    COUNT(DISTINCT CASE WHEN e.is_active = TRUE THEN e.entity_id END) AS active_count,
    COUNT(DISTINCT ck.chunk_id) AS total_chunks
FROM entity_types et
LEFT JOIN university_entities e ON et.type_id = e.entity_type_id
LEFT JOIN entity_knowledge_chunks ck ON e.entity_id = ck.entity_id
GROUP BY et.type_id, et.type_name, et.type_label, et.icon;

-- Search query analysis
CREATE OR REPLACE VIEW v_search_query_analysis AS
SELECT
    DATE(timestamp) AS search_date,
    COUNT(*) AS total_searches,
    AVG(documents_found) AS avg_documents_found,
    AVG(avg_similarity_score) AS avg_similarity,
    AVG(search_duration_ms) AS avg_search_duration,
    SUM(CASE WHEN user_satisfied = TRUE THEN 1 ELSE 0 END) AS satisfied_searches,
    ROUND((SUM(CASE WHEN user_satisfied = TRUE THEN 1 ELSE 0 END) / COUNT(*) * 100), 2) AS satisfaction_rate
FROM search_analytics
GROUP BY DATE(timestamp)
ORDER BY search_date DESC;

-- ========================================
-- SECTION 16: STORED PROCEDURES
-- ========================================

DELIMITER //

-- Update conversation statistics
CREATE PROCEDURE sp_update_conversation_stats(IN p_conversation_id INT)
BEGIN
    UPDATE conversations c
    SET
        total_messages = (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = p_conversation_id),
        user_messages_count = (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = p_conversation_id AND sender_type = 'user'),
        bot_messages_count = (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = p_conversation_id AND sender_type = 'bot'),
        avg_response_time_ms = (SELECT AVG(response_time_ms) FROM chat_messages WHERE conversation_id = p_conversation_id AND sender_type = 'bot')
    WHERE conversation_id = p_conversation_id;
END //

-- Calculate daily analytics
CREATE PROCEDURE sp_calculate_daily_analytics(IN p_date DATE)
BEGIN
    INSERT INTO analytics_daily (
        analytics_date, total_sessions, active_sessions, total_conversations,
        total_messages, successful_responses, failed_responses, avg_response_time_ms, avg_confidence_score
    )
    SELECT
        p_date,
        COUNT(DISTINCT s.session_id),
        COUNT(DISTINCT CASE WHEN DATE(s.start_time) = p_date THEN s.session_id END),
        COUNT(DISTINCT c.conversation_id),
        COUNT(m.message_id),
        SUM(CASE WHEN m.response_type IN ('rag_based', 'faq') THEN 1 ELSE 0 END),
        SUM(CASE WHEN m.response_type IN ('error', 'fallback') THEN 1 ELSE 0 END),
        AVG(m.response_time_ms),
        AVG(m.confidence_score)
    FROM web_sessions s
    LEFT JOIN conversations c ON s.session_id = c.session_id
    LEFT JOIN chat_messages m ON c.conversation_id = m.conversation_id
    WHERE DATE(s.start_time) = p_date
    ON DUPLICATE KEY UPDATE
        total_sessions = VALUES(total_sessions),
        active_sessions = VALUES(active_sessions),
        total_conversations = VALUES(total_conversations),
        total_messages = VALUES(total_messages),
        successful_responses = VALUES(successful_responses),
        failed_responses = VALUES(failed_responses),
        avg_response_time_ms = VALUES(avg_response_time_ms),
        avg_confidence_score = VALUES(avg_confidence_score);
END //

-- Get entity hierarchy path (recursive walk to root)
CREATE PROCEDURE sp_get_entity_path(IN p_entity_id INT)
BEGIN
    WITH RECURSIVE entity_path AS (
        SELECT entity_id, name, parent_entity_id, entity_type_id, 1 AS level
        FROM university_entities
        WHERE entity_id = p_entity_id

        UNION ALL

        SELECT e.entity_id, e.name, e.parent_entity_id, e.entity_type_id, ep.level + 1
        FROM university_entities e
        INNER JOIN entity_path ep ON e.entity_id = ep.parent_entity_id
    )
    SELECT ep.entity_id, ep.name, ep.level, et.type_name, et.type_label
    FROM entity_path ep
    INNER JOIN entity_types et ON ep.entity_type_id = et.type_id
    ORDER BY ep.level DESC;
END //

-- Get entity with full details (structured data, chunks, relationships)
CREATE PROCEDURE sp_get_entity_full(IN p_entity_id INT)
BEGIN
    -- Main entity info with type and parent
    SELECT
        e.*,
        et.type_name, et.type_label, et.icon, et.field_schema,
        p.name AS parent_name,
        pt.type_name AS parent_type,
        u.name AS university_name,
        a1.full_name AS created_by_name,
        a2.full_name AS updated_by_name
    FROM university_entities e
    INNER JOIN entity_types et ON e.entity_type_id = et.type_id
    LEFT JOIN university_entities p ON e.parent_entity_id = p.entity_id
    LEFT JOIN entity_types pt ON p.entity_type_id = pt.type_id
    LEFT JOIN university_entities u ON e.university_id = u.entity_id
    LEFT JOIN admins a1 ON e.created_by = a1.admin_id
    LEFT JOIN admins a2 ON e.updated_by = a2.admin_id
    WHERE e.entity_id = p_entity_id;

    -- Knowledge chunks
    SELECT * FROM entity_knowledge_chunks
    WHERE entity_id = p_entity_id AND is_active = TRUE
    ORDER BY chunk_index;

    -- Relationships (as source)
    SELECT r.*, e.name AS target_name, et.type_label AS target_type
    FROM entity_relationships r
    INNER JOIN university_entities e ON r.target_entity_id = e.entity_id
    INNER JOIN entity_types et ON e.entity_type_id = et.type_id
    WHERE r.source_entity_id = p_entity_id AND r.is_active = TRUE;

    -- Relationships (as target)
    SELECT r.*, e.name AS source_name, et.type_label AS source_type
    FROM entity_relationships r
    INNER JOIN university_entities e ON r.source_entity_id = e.entity_id
    INNER JOIN entity_types et ON e.entity_type_id = et.type_id
    WHERE r.target_entity_id = p_entity_id AND r.is_active = TRUE;

    -- Direct children
    SELECT e.entity_id, e.name, e.entity_code, et.type_name, et.type_label, et.icon, e.is_active
    FROM university_entities e
    INNER JOIN entity_types et ON e.entity_type_id = et.type_id
    WHERE e.parent_entity_id = p_entity_id
    ORDER BY e.display_order, e.name;
END //

DELIMITER ;

-- ========================================
-- SECTION 17: TRIGGERS
-- ========================================

DELIMITER //

-- Auto-update session duration
CREATE TRIGGER tr_update_session_duration
BEFORE UPDATE ON web_sessions
FOR EACH ROW
BEGIN
    IF NEW.end_time IS NOT NULL THEN
        SET NEW.duration_seconds = TIMESTAMPDIFF(SECOND, NEW.start_time, NEW.end_time);
    END IF;
END //

-- Log entity changes
CREATE TRIGGER tr_log_entity_changes
AFTER UPDATE ON university_entities
FOR EACH ROW
BEGIN
    IF OLD.name != NEW.name OR OLD.is_active != NEW.is_active OR OLD.parent_entity_id != NEW.parent_entity_id
       OR OLD.structured_data != NEW.structured_data OR OLD.description != NEW.description THEN
        INSERT INTO entity_history (entity_id, action, version, old_data, new_data, changed_by, created_at)
        VALUES (
            NEW.entity_id,
            'updated',
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.metadata, '$.version')), '1'),
            JSON_OBJECT('name', OLD.name, 'is_active', OLD.is_active, 'description', LEFT(COALESCE(OLD.description,''), 500)),
            JSON_OBJECT('name', NEW.name, 'is_active', NEW.is_active, 'description', LEFT(COALESCE(NEW.description,''), 500)),
            NEW.updated_by,
            NOW()
        );
    END IF;
END //

DELIMITER ;

-- ========================================
-- SECTION 18: INDEXES FOR PERFORMANCE
-- ========================================

-- Additional composite indexes for common queries
CREATE INDEX idx_sessions_start_time ON web_sessions(start_time);
CREATE INDEX idx_entity_type_active ON university_entities(entity_type_id, is_active);
CREATE INDEX idx_entity_parent_order ON university_entities(parent_entity_id, display_order);
CREATE INDEX idx_chunks_entity_index ON entity_knowledge_chunks(entity_id, chunk_index);
CREATE INDEX idx_message_context_similarity ON message_context(message_id, similarity_score DESC);
CREATE INDEX idx_search_analytics_date_satisfied ON search_analytics(timestamp, user_satisfied);

-- ========================================
-- END OF SCHEMA
-- ========================================

-- ========================================
-- SETUP USER PRIVILEGES
-- ========================================
DROP USER IF EXISTS 'campus_ai_user'@'localhost';
CREATE USER 'campus_ai_user'@'localhost' IDENTIFIED BY 'root';
GRANT ALL PRIVILEGES ON campus_ai_db.* TO 'campus_ai_user'@'localhost';
FLUSH PRIVILEGES;
