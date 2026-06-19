

# LAN / WiFi Access Guide 

## Step 1 — Make Sure Services Are Running

Start these on your main computer:
* **XAMPP Apache** (port **80**)
* **Backend API** (port **8000**)


## Step 2 — Find Your Computer’s Local IP
hostname -I

## Step 3 — Allow Firewall Ports
sudo ufw allow 80/tcp
sudo ufw allow 8000/tcp
sudo ufw allow 5173/tcp


## Step 4 — Make Sure Backend Runs on All Devices
Your backend must run on **0.0.0.0** (not localhost).
Example: ```bash uvicorn main:app --host 0.0.0.0 --port 8000 ```


## Step 5 — Start the Frontend
npm run build  # Rebuild with new .env
npm run dev -- --host 0.0.0.0  # Allow external access
ngrok http 5173

## Step 6 — Access From Another Device

On another laptop or phone connected to the **same WiFi**, open a browser and use your IP.

Example if your IP is **192.168.1.100**:

 =============================================================================================
| **Service**      | **Open this in the browser**                                             |
| ---------------- | ------------------------------------------------------------------------ |
| Admin Panel      | [http://192.168.1.100/Admin-Finale/]                                     |
| Chatbot Frontend | [http://192.168.1.100:5173]                                              |
| Backend API      | [http://192.168.1.100:8000]                                              |
 =============================================================================================

## Step 7 — If Frontend Uses `localhost`

Change the API URL in `.env`:

VITE_API_URL=http://192.168.1.100:8000

Restart the frontend after changing it.

