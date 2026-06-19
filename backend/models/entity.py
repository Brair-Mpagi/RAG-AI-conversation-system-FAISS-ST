"""SQLAlchemy models for the entity-based knowledge base.

Tables:
  - entity_types             : extensible type registry
  - university_entities      : core entity store with hierarchy
  - entity_relationships     : explicit links between entities
  - entity_knowledge_chunks  : RAG-ready text chunks per entity
  - entity_history           : audit trail for entity changes
"""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import (
    Boolean,
    Column,
    DateTime,
    ForeignKey,
    Integer,
    String,
    Text,
    JSON,
    func,
)
from sqlalchemy.orm import relationship, Mapped, mapped_column

from databases.session import Base


class EntityType(Base):
    __tablename__ = "entity_types"

    type_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    type_name: Mapped[str] = mapped_column(String(100), unique=True, nullable=False)
    type_label: Mapped[str] = mapped_column(String(255), nullable=False)
    icon: Mapped[str | None] = mapped_column(String(100), default="fa-cube")
    description: Mapped[str | None] = mapped_column(Text)
    parent_type_id: Mapped[int | None] = mapped_column(Integer, ForeignKey("entity_types.type_id", ondelete="SET NULL"))
    field_schema: Mapped[dict | None] = mapped_column(JSON)
    display_order: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now())

    # Relationships
    entities = relationship("UniversityEntity", back_populates="entity_type", foreign_keys="UniversityEntity.entity_type_id")
    parent_type = relationship("EntityType", remote_side="EntityType.type_id", foreign_keys=[parent_type_id])


class UniversityEntity(Base):
    __tablename__ = "university_entities"

    entity_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    entity_type_id: Mapped[int] = mapped_column(Integer, ForeignKey("entity_types.type_id", ondelete="RESTRICT"), nullable=False)
    entity_code: Mapped[str | None] = mapped_column(String(50))
    university_id: Mapped[int | None] = mapped_column(Integer, ForeignKey("university_entities.entity_id", ondelete="SET NULL"))
    parent_entity_id: Mapped[int | None] = mapped_column(Integer, ForeignKey("university_entities.entity_id", ondelete="SET NULL"))
    name: Mapped[str] = mapped_column(String(500), nullable=False)
    short_name: Mapped[str | None] = mapped_column(String(100))
    description: Mapped[str | None] = mapped_column(Text)
    structured_data: Mapped[dict | None] = mapped_column(JSON)
    metadata_: Mapped[dict | None] = mapped_column("metadata", JSON)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    display_order: Mapped[int] = mapped_column(Integer, default=0)
    created_by: Mapped[int | None] = mapped_column(Integer, ForeignKey("admins.admin_id", ondelete="SET NULL"))
    updated_by: Mapped[int | None] = mapped_column(Integer, ForeignKey("admins.admin_id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now())

    # Relationships
    entity_type = relationship("EntityType", back_populates="entities", foreign_keys=[entity_type_id])
    parent = relationship("UniversityEntity", remote_side="UniversityEntity.entity_id", foreign_keys=[parent_entity_id])
    university = relationship("UniversityEntity", remote_side="UniversityEntity.entity_id", foreign_keys=[university_id])
    children = relationship("UniversityEntity", foreign_keys=[parent_entity_id], back_populates="parent")
    knowledge_chunks = relationship("EntityKnowledgeChunk", back_populates="entity", cascade="all, delete-orphan")
    source_relationships = relationship("EntityRelationship", foreign_keys="EntityRelationship.source_entity_id", back_populates="source_entity")
    target_relationships = relationship("EntityRelationship", foreign_keys="EntityRelationship.target_entity_id", back_populates="target_entity")


class EntityRelationship(Base):
    __tablename__ = "entity_relationships"

    relationship_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    source_entity_id: Mapped[int] = mapped_column(Integer, ForeignKey("university_entities.entity_id", ondelete="CASCADE"), nullable=False)
    target_entity_id: Mapped[int] = mapped_column(Integer, ForeignKey("university_entities.entity_id", ondelete="CASCADE"), nullable=False)
    relationship_type: Mapped[str] = mapped_column(String(100), nullable=False)
    description: Mapped[str | None] = mapped_column(Text)
    metadata_: Mapped[dict | None] = mapped_column("metadata", JSON)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now())

    # Relationships
    source_entity = relationship("UniversityEntity", foreign_keys=[source_entity_id], back_populates="source_relationships")
    target_entity = relationship("UniversityEntity", foreign_keys=[target_entity_id], back_populates="target_relationships")


class EntityKnowledgeChunk(Base):
    __tablename__ = "entity_knowledge_chunks"

    chunk_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    entity_id: Mapped[int] = mapped_column(Integer, ForeignKey("university_entities.entity_id", ondelete="CASCADE"), nullable=False)
    chunk_index: Mapped[int] = mapped_column(Integer, default=0)
    title: Mapped[str] = mapped_column(String(500), nullable=False)
    content: Mapped[str] = mapped_column(Text, nullable=False)
    chunk_metadata: Mapped[dict | None] = mapped_column(JSON)
    token_count: Mapped[int | None] = mapped_column(Integer)
    char_count: Mapped[int | None] = mapped_column(Integer)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now())

    # Relationships
    entity = relationship("UniversityEntity", back_populates="knowledge_chunks")


class EntityHistory(Base):
    __tablename__ = "entity_history"

    history_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    entity_id: Mapped[int] = mapped_column(Integer, ForeignKey("university_entities.entity_id", ondelete="CASCADE"), nullable=False)
    action: Mapped[str] = mapped_column(String(20), nullable=False)
    version: Mapped[int] = mapped_column(Integer, default=1)
    old_data: Mapped[dict | None] = mapped_column(JSON)
    new_data: Mapped[dict | None] = mapped_column(JSON)
    changed_by: Mapped[int | None] = mapped_column(Integer, ForeignKey("admins.admin_id", ondelete="SET NULL"))
    change_reason: Mapped[str | None] = mapped_column(Text)
    ip_address: Mapped[str | None] = mapped_column(String(45))
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
