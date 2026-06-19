

## backend
```bash
uvicorn main:app --host 0.0.0.0 --port 8000 --reload
```

## frontend
```bash
npm run build  # Rebuild with new .env
npm run dev -- --host 0.0.0.0  # Allow external access
```




## virrtual environment


## ✅ Python 3.12.7 on Kali using **pyenv** (ZSH)

### 1️⃣ Install dependencies
```bash
sudo apt update
sudo apt install -y \
  build-essential make git curl \
  libssl-dev zlib1g-dev libbz2-dev \
  libreadline-dev libsqlite3-dev \
  libffi-dev liblzma-dev \
  tk-dev xz-utils
```


### 2️⃣ Install pyenv
```bash
curl https://pyenv.run | bash
```

### 3️⃣ Configure pyenv for **zsh**
echo 'export PYENV_ROOT="$HOME/.pyenv"' >> ~/.zshrc
echo 'export PATH="$PYENV_ROOT/bin:$PATH"' >> ~/.zshrc
echo 'eval "$(pyenv init - zsh)"' >> ~/.zshrc

# Reload shell:
exec zsh

### 4️⃣ Install Python 3.12.7
pyenv install 3.12.7

### 5️⃣ Set Python version for your project
cd ~/Desktop/Research_Project/University_Ai_Chatbot_System/backend
pyenv local 3.12.7
pyenv rehash

# Verify:
python --version
Python 3.12.7


### 6️⃣ Create & activate virtual environment
python -m venv backend_env
source backend_env/bin/activate
pip install --upgrade pip setuptools wheel
pip install -r requirements.txt



## -==========================
## Docker
Listed directory University_Ai_Chatbot_System
Viewed docker-compose.yml:1-66
Viewed run.md:1-63

You can run the entire project (database, backend, and frontend) using the existing [docker-compose.yml](cci:7://file:///home/bcodz/Desktop/University_Ai_Chatbot_System/docker-compose.yml:0:0-0:0) file. 

Here are the step-by-step instructions to get everything up and running:

### 1. Prerequisites
- Ensure you have **Docker** and **Docker Compose** installed and running on your system.
- The [docker-compose.yml](cci:7://file:///home/bcodz/Desktop/University_Ai_Chatbot_System/docker-compose.yml:0:0-0:0) file assumes you are running **Ollama** natively on your host machine for the LLM component (`http://host.docker.internal:11434`). Make sure Ollama is active.

### 2. Environment Variables
The backend container requires a `.env` file in the `backend/` directory to load variables.
If you don't have one set up yet, you can create a placeholder by running this command in your project root:
```bash
echo "DB_PASSWORD=changeme" > backend/.env
```
*(The compose file is configured to default your MySQL root and DB passwords to `changeme` if variables are missing).*

### 3. Build and Start the Containers
Open your terminal in the root directory of the project (`/home/bcodz/Desktop/University_Ai_Chatbot_System`) and run:

```bash
docker-compose up -d --build
```
*(Note: If you have a newer docker version, the command might be `docker compose up -d --build` without the hyphen).*

This command will:
- **`db`:** Pull `mariadb:10.11`, expose it on port `3307`, and automatically initialize the database using your `database/schema.sql` file.
- **`backend`:** Build the FastAPI environment using your existing `backend/Dockerfile` and run it on port `8000`.
- **`frontend`:** Spin up a Node.js alpine container, install dependencies on the fly, and run Vite on port `5173`.

### 4. Verify Services
You can verify that all 3 containers are healthy by checking their status:
```bash
docker-compose ps
```

If you need to troubleshoot, you can view real-time logs for a specific service (e.g., the backend):
```bash
docker-compose logs -f backend
```

### Accessing the Application
Once the containers are running smoothly, you can access your services at:
- **Frontend UI:** [http://localhost:5173](http://localhost:5173)
- **Backend API Interface:** [http://localhost:8000/docs](http://localhost:8000/docs)
- **Database Server:** Host `127.0.0.1`, Port `3307` (User: `campus_ai_user`, Password: `changeme`)

### Stopping the Project
When you're finished, you can cleanly spin down all the containers with:
```bash
docker-compose down
```


