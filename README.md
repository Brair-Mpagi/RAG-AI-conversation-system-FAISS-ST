# RAG-NLP-AI-Assistant

An intelligent, RAG-powered chatbot for **(MMU)** that answers student and staff queries about campus life, courses, staff, and events — using locally-hosted LLMs via **Ollama** for full data privacy.

---

## Project Architecture

```
Admin_F --> this to local web development environment {LAMPP}
pjt-chatbot/
├── backend/            # FastAPI Python backend (RAG + LLM + REST/WebSocket API)
├── Frontend/
│   ├── web_interface/  # Vite + React embeddable chat widget
│   └── app_interface/  # Capacitor React companion app (web + Android)
├── database/           # MySQL schema & migrations
├── scripts/            # Data ingestion, scraping & utility scripts
├── docker-compose.yml  # One-command stack launcher
└── README.md
```

---

## ⚙️ Prerequisites

| Tool | Version | Purpose |
|---|---|---|
| [Docker](https://docs.docker.com/get-docker/) | 24+ | Run the full stack |
| [Docker Compose](https://docs.docker.com/compose/) | v2+ | Orchestrate services |
| [Ollama](https://ollama.com/) | latest | Host the LLM locally on your machine |
| Node.js *(dev only)* | 20+ | Frontend development |
| Python *(dev only)* | 3.12.7 | Backend development |

> **Note:** Ollama must be running **natively on your host machine** (not inside Docker) so the backend container can reach it at `http://host.docker.internal:11434`.

---

## Quick Start (Docker — Recommended)

### 1. Clone the repository
```bash
git clone https://github.com/<your-username>/<repo-name>.git
cd <repo-name>
```

### 2. Pull the required LLM model
```bash
ollama pull llama3.2:3b-instruct-q4_K_M
# fallback (smaller):
ollama pull tinyllama:latest
```

### 3. Configure environment variables
```bash
cp backend/.env.example backend/.env
```
Open `backend/.env` and update:
- `DB_PASSWORD` — choose a strong password
- `SECRET_KEY` — generate with `openssl rand -hex 32`
- Any `CORS_ORIGINS` that your deployment needs

### 4. Build and start all services
```bash
docker compose up -d --build
```

This spins up:
| Service | URL |
|---|---|
| **FastAPI backend** | http://localhost:8000 |
| **API docs (Swagger)** | http://localhost:8000/docs |
| **Web chat widget** | http://localhost:5173 |
| **Companion app** | http://localhost:5174 |
| **MariaDB** | `127.0.0.1:3307` |


## ---> Docker is optioal
### 5. Verify everything is running
```bash
docker compose ps
docker compose logs -f backend   # stream backend logs
```

### 6. Stop the stack
```bash
docker compose down
```

---

## 🔧 Manual / Development Setup

### Backend (FastAPI)

```bash
# 1. Install pyenv + Python 3.12.7 (skip if you already have it)
curl https://pyenv.run | bash
pyenv install 3.12.7
pyenv local 3.12.7      # run from the backend/ directory

# 2. Create a virtual environment
cd backend/
python -m venv backend_env
source backend_env/bin/activate   # Windows: backend_env\Scripts\activate

# 3. Install dependencies
pip install --upgrade pip setuptools wheel
pip install -r requirements.txt

# 4. Configure .env (copy & edit)
cp .env.example .env

# 5. Run the dev server
uvicorn main:app --host 0.0.0.0 --port 8000 --reload
```

### Frontend – Web Widget

```bash
cd Frontend/web_interface/
npm install
npm run dev -- --host 0.0.0.0
# → http://localhost:5173
```

### Frontend – Companion App

```bash
cd Frontend/app_interface/
npm install
npm run dev -- --host 0.0.0.0
# → http://localhost:5174
```

---

## 🗄️ Database

The schema is in [`database/schema.sql`](database/schema.sql).  
When using Docker Compose, MariaDB initialises automatically from this file on first boot.

For manual MySQL/XAMPP setup:
```bash
mysql -u root -p < database/schema.sql
```

---

## Vector Store (RAG)

The FAISS vector index is **not included in the repository** (large binary files).  
After cloning, build it from your scraped data:

```bash
cd backend/
source backend_env/bin/activate
python scripts/build_vector_store.py
```
Or use the full scrape + pipeline:
```bash
python scripts/web_scraper.py
python scripts/post_scrape_pipeline.py
```

---

## 📁 Key Files

| File | Purpose |
|---|---|
| `backend/.env.example` | Template for all environment variables |
| `backend/main.py` | FastAPI application entry point |
| `backend/requirements.txt` | Python dependencies |
| `docker-compose.yml` | Full-stack Docker orchestration |
| `database/schema.sql` | MySQL database schema |
| `scripts/web_scraper.py` | MMU website scraper |
| `scripts/build_vector_store.py` | Builds the FAISS RAG index |
| `scripts/seed_admin.py` | Seeds the first admin user |

---

## 🌐 LAN / Network Access

To allow other devices on your local network to use the chatbot, add your machine's IP to `CORS_ORIGINS` in `backend/.env`:

```
CORS_ORIGINS=http://localhost:5173,http://<YOUR_LAN_IP>:5173
```

See [`LAN_ACCESS.md`](LAN_ACCESS.md) for full instructions.

---

## Contributing

1. Fork the repo
2. Create a feature branch: `git checkout -b feat/your-feature`
3. Commit your changes: `git commit -m "feat: add your feature"`
4. Push to the branch: `git push origin feat/your-feature`
5. Open a Pull Request

---

## 📄 License

This project is developed as an academic research project. 
