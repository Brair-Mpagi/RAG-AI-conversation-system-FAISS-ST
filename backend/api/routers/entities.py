"""Entity management API router.

CRUD for entity types, university entities, and knowledge chunks.
Provides hierarchy tree endpoint for admin UI consumption.
"""

from __future__ import annotations

import logging
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query
from pydantic import BaseModel, Field
from sqlalchemy import text, func as sa_func
from sqlalchemy.orm import Session

from databases.session import get_db

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/v1", tags=["entities"])


# ---- Pydantic Schemas ----

class EntityTypeIn(BaseModel):
    type_name: str
    type_label: str
    icon: str = "fa-cube"
    description: Optional[str] = None
    parent_type_id: Optional[int] = None
    field_schema: Optional[Dict[str, Any]] = None
    display_order: int = 0
    is_active: bool = True


class EntityIn(BaseModel):
    entity_type_id: int
    entity_code: Optional[str] = None
    university_id: Optional[int] = None
    parent_entity_id: Optional[int] = None
    name: str
    short_name: Optional[str] = None
    description: Optional[str] = None
    structured_data: Optional[Dict[str, Any]] = None
    metadata: Optional[Dict[str, Any]] = None
    is_active: bool = True
    display_order: int = 0


class ChunkIn(BaseModel):
    chunk_index: int = 0
    title: str
    content: str
    chunk_metadata: Optional[Dict[str, Any]] = None


# ---- Entity Type Endpoints ----

@router.get("/entity-types")
def list_entity_types(db: Session = Depends(get_db)) -> List[Dict[str, Any]]:
    rows = db.execute(text(
        "SELECT type_id, type_name, type_label, icon, description, parent_type_id, "
        "field_schema, display_order, is_active FROM entity_types ORDER BY display_order, type_label"
    )).mappings().all()
    return [dict(r) for r in rows]


@router.post("/entity-types")
def create_entity_type(body: EntityTypeIn, db: Session = Depends(get_db)) -> Dict[str, Any]:
    db.execute(text(
        "INSERT INTO entity_types (type_name, type_label, icon, description, parent_type_id, field_schema, display_order, is_active) "
        "VALUES (:type_name, :type_label, :icon, :description, :parent_type_id, :field_schema, :display_order, :is_active)"
    ), {
        "type_name": body.type_name,
        "type_label": body.type_label,
        "icon": body.icon,
        "description": body.description,
        "parent_type_id": body.parent_type_id,
        "field_schema": body.field_schema if body.field_schema is None else str(body.field_schema).replace("'", '"'),
        "display_order": body.display_order,
        "is_active": body.is_active,
    })
    db.commit()
    row = db.execute(text("SELECT LAST_INSERT_ID() AS id")).mappings().first()
    return {"status": "ok", "type_id": row["id"]}


@router.put("/entity-types/{type_id}")
def update_entity_type(type_id: int, body: EntityTypeIn, db: Session = Depends(get_db)) -> Dict[str, Any]:
    result = db.execute(text(
        "UPDATE entity_types SET type_name=:type_name, type_label=:type_label, icon=:icon, "
        "description=:description, parent_type_id=:parent_type_id, field_schema=:field_schema, "
        "display_order=:display_order, is_active=:is_active WHERE type_id=:type_id"
    ), {**body.model_dump(), "type_id": type_id,
        "field_schema": body.field_schema if body.field_schema is None else str(body.field_schema).replace("'", '"')})
    db.commit()
    if result.rowcount == 0:
        raise HTTPException(status_code=404, detail="Entity type not found")
    return {"status": "ok"}


@router.delete("/entity-types/{type_id}")
def delete_entity_type(type_id: int, db: Session = Depends(get_db)) -> Dict[str, Any]:
    # Check for entities of this type
    count = db.execute(text(
        "SELECT COUNT(*) AS cnt FROM university_entities WHERE entity_type_id=:type_id"
    ), {"type_id": type_id}).mappings().first()["cnt"]
    if count > 0:
        raise HTTPException(status_code=400, detail=f"Cannot delete: {count} entities use this type")
    db.execute(text("DELETE FROM entity_types WHERE type_id=:type_id"), {"type_id": type_id})
    db.commit()
    return {"status": "ok"}


# ---- Entity Endpoints ----

@router.get("/entities")
def list_entities(
    entity_type: Optional[str] = Query(None),
    parent_id: Optional[int] = Query(None),
    university_id: Optional[int] = Query(None),
    is_active: Optional[bool] = Query(None),
    search: Optional[str] = Query(None),
    limit: int = Query(100, le=500),
    offset: int = Query(0),
    db: Session = Depends(get_db),
) -> Dict[str, Any]:
    conditions = []
    params: Dict[str, Any] = {"limit": limit, "offset": offset}

    if entity_type:
        conditions.append("et.type_name = :entity_type")
        params["entity_type"] = entity_type
    if parent_id is not None:
        conditions.append("e.parent_entity_id = :parent_id")
        params["parent_id"] = parent_id
    if university_id is not None:
        conditions.append("e.university_id = :university_id")
        params["university_id"] = university_id
    if is_active is not None:
        conditions.append("e.is_active = :is_active")
        params["is_active"] = is_active
    if search:
        conditions.append("(e.name LIKE :search OR e.entity_code LIKE :search)")
        params["search"] = f"%{search}%"

    where = ("WHERE " + " AND ".join(conditions)) if conditions else ""

    sql = f"""
        SELECT e.entity_id, e.entity_type_id, et.type_name, et.type_label, et.icon,
               e.entity_code, e.university_id, e.parent_entity_id,
               e.name, e.short_name, e.description,
               e.structured_data, e.metadata, e.is_active, e.display_order,
               p.name AS parent_name,
               (SELECT COUNT(*) FROM university_entities c WHERE c.parent_entity_id = e.entity_id) AS children_count,
               (SELECT COUNT(*) FROM entity_knowledge_chunks ck WHERE ck.entity_id = e.entity_id AND ck.is_active = TRUE) AS chunk_count
        FROM university_entities e
        INNER JOIN entity_types et ON e.entity_type_id = et.type_id
        LEFT JOIN university_entities p ON e.parent_entity_id = p.entity_id
        {where}
        ORDER BY e.display_order, e.name
        LIMIT :limit OFFSET :offset
    """
    rows = db.execute(text(sql), params).mappings().all()

    count_sql = f"SELECT COUNT(*) AS total FROM university_entities e INNER JOIN entity_types et ON e.entity_type_id = et.type_id {where}"
    total = db.execute(text(count_sql), params).mappings().first()["total"]

    return {"entities": [dict(r) for r in rows], "total": total}


@router.get("/entities/tree")
def get_entity_tree(
    university_id: Optional[int] = Query(None),
    db: Session = Depends(get_db),
) -> List[Dict[str, Any]]:
    """Return a flat list of entities with parent_id for client-side tree building."""
    params: Dict[str, Any] = {}
    where = ""
    if university_id:
        where = "WHERE (e.university_id = :university_id OR e.entity_id = :university_id)"
        params["university_id"] = university_id

    sql = f"""
        SELECT e.entity_id, e.entity_type_id, et.type_name, et.type_label, et.icon,
               e.entity_code, e.parent_entity_id, e.university_id,
               e.name, e.short_name, e.is_active, e.display_order,
               (SELECT COUNT(*) FROM entity_knowledge_chunks ck WHERE ck.entity_id = e.entity_id AND ck.is_active = TRUE) AS chunk_count
        FROM university_entities e
        INNER JOIN entity_types et ON e.entity_type_id = et.type_id
        {where}
        ORDER BY e.display_order, e.name
    """
    rows = db.execute(text(sql), params).mappings().all()
    return [dict(r) for r in rows]


@router.get("/entities/{entity_id}")
def get_entity(entity_id: int, db: Session = Depends(get_db)) -> Dict[str, Any]:
    sql = """
        SELECT e.*, et.type_name, et.type_label, et.icon, et.field_schema,
               p.name AS parent_name,
               u.name AS university_name,
               (SELECT COUNT(*) FROM university_entities c WHERE c.parent_entity_id = e.entity_id) AS children_count
        FROM university_entities e
        INNER JOIN entity_types et ON e.entity_type_id = et.type_id
        LEFT JOIN university_entities p ON e.parent_entity_id = p.entity_id
        LEFT JOIN university_entities u ON e.university_id = u.entity_id
        WHERE e.entity_id = :entity_id
    """
    row = db.execute(text(sql), {"entity_id": entity_id}).mappings().first()
    if not row:
        raise HTTPException(status_code=404, detail="Entity not found")

    entity = dict(row)

    # Get chunks
    chunks = db.execute(text(
        "SELECT * FROM entity_knowledge_chunks WHERE entity_id = :entity_id AND is_active = TRUE ORDER BY chunk_index"
    ), {"entity_id": entity_id}).mappings().all()
    entity["chunks"] = [dict(c) for c in chunks]

    # Get children
    children = db.execute(text(
        "SELECT e.entity_id, e.name, e.entity_code, et.type_name, et.type_label, et.icon, e.is_active "
        "FROM university_entities e INNER JOIN entity_types et ON e.entity_type_id = et.type_id "
        "WHERE e.parent_entity_id = :entity_id ORDER BY e.display_order, e.name"
    ), {"entity_id": entity_id}).mappings().all()
    entity["children"] = [dict(c) for c in children]

    return entity


@router.post("/entities")
def create_entity(body: EntityIn, db: Session = Depends(get_db)) -> Dict[str, Any]:
    import json
    db.execute(text(
        "INSERT INTO university_entities "
        "(entity_type_id, entity_code, university_id, parent_entity_id, name, short_name, description, "
        "structured_data, metadata, is_active, display_order) "
        "VALUES (:entity_type_id, :entity_code, :university_id, :parent_entity_id, :name, :short_name, "
        ":description, :structured_data, :metadata, :is_active, :display_order)"
    ), {
        "entity_type_id": body.entity_type_id,
        "entity_code": body.entity_code,
        "university_id": body.university_id,
        "parent_entity_id": body.parent_entity_id,
        "name": body.name,
        "short_name": body.short_name,
        "description": body.description,
        "structured_data": json.dumps(body.structured_data) if body.structured_data else None,
        "metadata": json.dumps(body.metadata) if body.metadata else None,
        "is_active": body.is_active,
        "display_order": body.display_order,
    })
    db.commit()
    row = db.execute(text("SELECT LAST_INSERT_ID() AS id")).mappings().first()
    return {"status": "ok", "entity_id": row["id"]}


@router.put("/entities/{entity_id}")
def update_entity(entity_id: int, body: EntityIn, db: Session = Depends(get_db)) -> Dict[str, Any]:
    import json
    result = db.execute(text(
        "UPDATE university_entities SET entity_type_id=:entity_type_id, entity_code=:entity_code, "
        "university_id=:university_id, parent_entity_id=:parent_entity_id, name=:name, short_name=:short_name, "
        "description=:description, structured_data=:structured_data, metadata=:metadata, "
        "is_active=:is_active, display_order=:display_order WHERE entity_id=:entity_id"
    ), {
        **{k: (json.dumps(v) if k in ("structured_data", "metadata") and v is not None else v)
           for k, v in body.model_dump().items()},
        "entity_id": entity_id,
    })
    db.commit()
    if result.rowcount == 0:
        raise HTTPException(status_code=404, detail="Entity not found")
    return {"status": "ok"}


@router.delete("/entities/{entity_id}")
def delete_entity(entity_id: int, db: Session = Depends(get_db)) -> Dict[str, Any]:
    # Check for children
    count = db.execute(text(
        "SELECT COUNT(*) AS cnt FROM university_entities WHERE parent_entity_id=:entity_id"
    ), {"entity_id": entity_id}).mappings().first()["cnt"]
    if count > 0:
        raise HTTPException(status_code=400, detail=f"Cannot delete: entity has {count} children. Delete children first.")
    db.execute(text("DELETE FROM university_entities WHERE entity_id=:entity_id"), {"entity_id": entity_id})
    db.commit()
    return {"status": "ok"}


# ---- Knowledge Chunk Endpoints ----

@router.get("/entities/{entity_id}/chunks")
def list_chunks(entity_id: int, db: Session = Depends(get_db)) -> List[Dict[str, Any]]:
    rows = db.execute(text(
        "SELECT * FROM entity_knowledge_chunks WHERE entity_id = :entity_id ORDER BY chunk_index"
    ), {"entity_id": entity_id}).mappings().all()
    return [dict(r) for r in rows]


@router.post("/entities/{entity_id}/chunks")
def create_chunk(entity_id: int, body: ChunkIn, db: Session = Depends(get_db)) -> Dict[str, Any]:
    import json
    # Verify entity exists
    entity = db.execute(text("SELECT entity_id FROM university_entities WHERE entity_id=:id"), {"id": entity_id}).mappings().first()
    if not entity:
        raise HTTPException(status_code=404, detail="Entity not found")

    db.execute(text(
        "INSERT INTO entity_knowledge_chunks (entity_id, chunk_index, title, content, chunk_metadata, char_count) "
        "VALUES (:entity_id, :chunk_index, :title, :content, :chunk_metadata, :char_count) "
        "ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), chunk_metadata=VALUES(chunk_metadata), char_count=VALUES(char_count)"
    ), {
        "entity_id": entity_id,
        "chunk_index": body.chunk_index,
        "title": body.title,
        "content": body.content,
        "chunk_metadata": json.dumps(body.chunk_metadata) if body.chunk_metadata else None,
        "char_count": len(body.content),
    })
    db.commit()
    return {"status": "ok"}


@router.delete("/entities/{entity_id}/chunks/{chunk_id}")
def delete_chunk(entity_id: int, chunk_id: int, db: Session = Depends(get_db)) -> Dict[str, Any]:
    db.execute(text(
        "DELETE FROM entity_knowledge_chunks WHERE chunk_id=:chunk_id AND entity_id=:entity_id"
    ), {"chunk_id": chunk_id, "entity_id": entity_id})
    db.commit()
    return {"status": "ok"}


# ---- Entity Stats ----

@router.get("/entities/stats/summary")
def entity_stats(db: Session = Depends(get_db)) -> Dict[str, Any]:
    rows = db.execute(text(
        "SELECT et.type_name, et.type_label, et.icon, "
        "COUNT(DISTINCT e.entity_id) AS entity_count, "
        "COUNT(DISTINCT CASE WHEN e.is_active = TRUE THEN e.entity_id END) AS active_count, "
        "COUNT(DISTINCT ck.chunk_id) AS total_chunks "
        "FROM entity_types et "
        "LEFT JOIN university_entities e ON et.type_id = e.entity_type_id "
        "LEFT JOIN entity_knowledge_chunks ck ON e.entity_id = ck.entity_id "
        "GROUP BY et.type_id, et.type_name, et.type_label, et.icon "
        "ORDER BY et.display_order"
    )).mappings().all()
    return {"stats": [dict(r) for r in rows]}
