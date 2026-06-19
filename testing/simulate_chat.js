const { chromium } = require('playwright');

// ==========================================
// CONFIGURATION
// ==========================================
// The local or network URL where your React chatbot is running
const CHATBOT_URL = 'http://localhost:5173'; 

// Total number of independent browser sessions/users to simulate simultaneously
const NUM_USERS = 30; 

// The sequence of messages each simulated user will send
const MESSAGES_PER_USER = [
    "Hello!",
    // "What are the admission requirements for MMU?",
    // "Where is the university located?",
];

// Random delay bounds (in milliseconds) used between opening pages, typing, and sending
// This simulates realistic human behavior and prevents instantly blocking your backend.
const MIN_DELAY = 1500;
const MAX_DELAY = 4000;

// ==========================================
// UTILITIES
// ==========================================
// Helper function to pause execution for a random time between min and max
const randomDelay = () => new Promise(res => setTimeout(res, MIN_DELAY + Math.random() * (MAX_DELAY - MIN_DELAY)));

// ==========================================
// USER SIMULATION LOGIC
// ==========================================
async function simulateUser(browser, userId) {
    // 1. Create a fully isolated browser context. 
    // This acts exactly like a fresh "Incognito/Private" window. No cookies, local storage, or session data is shared.
    const context = await browser.newContext();
    const page = await context.newPage();

    console.log(`[User ${userId}] Starting session...`);

    try {
        // 2. Navigate to the chatbot interface URL
        await page.goto(CHATBOT_URL);
        
        // Ensure we wait for the chatbot interface to fully render
        // 1. Click the floating chat bubble to open the actual chat window!
        const chatToggleButton = 'img[alt="Chat Icon"]';
        await page.waitForSelector(chatToggleButton);
        await page.click(chatToggleButton);
        
        // 2. Target the main chat input field and the send button paper plane icon
        const inputSelector = '[placeholder*="Type your message"]';
        const sendButtonSelector = 'button:has(i.fa-paper-plane)';
        
        await page.waitForSelector(inputSelector);
        console.log(`[User ${userId}] Chatbot loaded successfully.`);

        // 3. Send the planned message sequence
        for (let i = 0; i < MESSAGES_PER_USER.length; i++) {
            const msg = MESSAGES_PER_USER[i];
            
            // Wait briefly before typing to simulate natural reading/thinking time
            // If the bot is still responding from the last message, this input is disabled.
            // Playwright .fill() automatically waits for the input to become enabled again!
            await randomDelay();
            
            // Fill the input area with the message
            await page.fill(inputSelector, msg);
            console.log(`[User ${userId}] Typed: "${msg}"`);
            
            // Wait a fraction of a second before clicking send
            await page.waitForTimeout(500);
            
            // Click the submit button to send the chat
            await page.click(sendButtonSelector);
            
            console.log(`[User ${userId}] Sent message ${i+1}/${MESSAGES_PER_USER.length}.`);
        }

        console.log(`[User ${userId}] Finished sending all messages. Keeping window open for observation.`);
        
        // Note: We explicitly DO NOT call context.close() here so the UI remains visible for your review.

    } catch (e) {
        console.error(`[User ${userId}] Encountered an error:`, e.message);
    }
}

// ==========================================
// MAIN EXECUTION
// ==========================================
async function main() {
    console.log('Launching browser...');
    
    // Launch Chromium. 'headless: false' ensures the browser actually physically opens on your screen.
    // Explicitly targeting your local Kali Linux Chrome installation so it skips the massive binary downloads!
    const browser = await chromium.launch({ 
        headless: false,
        executablePath: '/usr/bin/google-chrome',
        // Optional: slowMo: 100 // Adds a 100ms delay to ALL Playwright actions so you can watch what it does
    });

    console.log(`Starting concurrent simulation for ${NUM_USERS} users...`);

    // Array to hold the independent async promises for all simulated users
    const userPromises = [];
    
    for (let i = 1; i <= NUM_USERS; i++) {
        // Start the user routine but don't strictly await its completion before firing the next one!
        userPromises.push(simulateUser(browser, i));
        
        // Stagger the users by launching them ~500ms apart to prevent crashing your system memory with 20 instant Chrome instances
        await new Promise(res => setTimeout(res, 500));
    }

    // Wait until ALL user sequences have fully resolved
    await Promise.all(userPromises);

    console.log('\n======================================================');
    console.log('✅ All automated messages have successfully been sent!');
    console.log('👀 Browsers will remain open indefinitely for observation.');
    console.log('🛑 Press Ctrl+C in this terminal when you are ready to kill the sessions.');
    console.log('======================================================\n');
}

// Run the script and catch top-level errors
main().catch(console.error);
