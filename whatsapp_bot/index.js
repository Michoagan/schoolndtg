const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const express = require('express');

const app = express();
app.use(express.json());

// Initialize WhatsApp Client
// LocalAuth saves the session so we don't need to scan the QR code every time
const client = new Client({
    authStrategy: new LocalAuth(),
    puppeteer: {
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || process.env.CHROME_BIN || undefined,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--no-first-run',
            '--no-zygote',
            '--disable-gpu'
        ]
    }
});

let isClientReady = false;

let latestQR = '';

client.on('loading_screen', (percent, message) => {
    console.log('🔄 Chargement WhatsApp Web...', percent, '%', message);
});

let noOfQrGenerated = 0;

client.on('qr', (qr) => {
    noOfQrGenerated++;
    console.log(`[${noOfQrGenerated}] 🔴 NOUVEAU QR CODE GÉNÉRÉ ! Allez vite sur votre page /qr pour le scanner.`);

    // On met à jour la variable pour que la page web affiche le dernier code valide
    latestQR = qr;
});

// Route Web pour afficher le QR code proprement dans le navigateur
app.get('/qr', (req, res) => {
    if (isClientReady) {
        return res.send('<h3 style="font-family: sans-serif; color: green; text-align: center; margin-top: 50px;">✅ Le bot est déjà connecté ! Pas besoin de scanner un code.</h3>');
    }
    if (!latestQR) {
        return res.send('<h3 style="font-family: sans-serif; text-align: center; margin-top: 50px;">⏳ Le QR code est en cours de génération... Veuillez rafraîchir cette page dans un instant.</h3><script>setTimeout(() => location.reload(), 3000)</script>');
    }

    res.send(`
        <html>
        <head>
            <title>Connexion WhatsApp Bot</title>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
            <style>
                body { display: flex; flex-direction: column; align-items: center; background: #e5ddd5; font-family: Helvetica, sans-serif; padding-top: 5vh; }
                .box { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
                #qrcode { margin-top: 20px; display: flex; justify-content: center; }
                h2 { color: #075e54; margin: 0 0 10px 0; }
                p { color: #555; }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>📱 Scannez pour connecter le Bot</h2>
                <p>1. Ouvrez WhatsApp sur votre téléphone.</p>
                <p>2. Allez dans <b>Appareils connectés</b> > <b>Connecter un appareil</b>.</p>
                <p>3. Pointez la caméra sur ce code :</p>
                <div id="qrcode"></div>
            </div>
            <script>
                new QRCode(document.getElementById("qrcode"), {
                    text: "${latestQR}",
                    width: 256,
                    height: 256,
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.M
                });

                // Rafraîchir pour voir s'il y a un nouveau QR ou si la connexion a réussi
                setTimeout(() => { location.reload(); }, 15000); 
            </script>
        </body>
        </html>
    `);
});

client.on('authenticated', () => {
    console.log('🔐 Authentification réussie !');
});

client.on('ready', () => {
    console.log('✅ WhatsApp Bot is Ready and Connected!');
    isClientReady = true;
});

client.on('disconnected', (reason) => {
    console.log('❌ WhatsApp Bot was disconnected: ', reason);
    isClientReady = false;
});

client.on('auth_failure', msg => {
    console.error('❌ Authentication failure', msg);
});

console.log('⏳ Initialisation de WhatsApp en cours... Veuillez patienter quelques secondes...');
client.initialize().catch(err => {
    console.error('🚨 Erreur CRITIQUE lors du lancement de Chrome/Puppeteer :', err);
});

// Express Endpoint to send messages
app.post('/send', async (req, res) => {
    // Répondre rapidement pour éviter le timeout côté Laravel (15s)
    const SEND_TIMEOUT_MS = 12000; // 12 secondes max

    const sendWithTimeout = new Promise(async (resolve, reject) => {
        try {
            if (!isClientReady) {
                return resolve({ success: false, error: "WhatsApp Client is not ready yet." });
            }

            const { phone, message } = req.body;

            if (!phone || !message) {
                return resolve({ success: false, error: "Phone number and message are required." });
            }

            // Formater le numéro
            let formattedPhone = phone.replace(/[^0-9]/g, '');

            if (formattedPhone.length === 10) {
                formattedPhone = '229' + formattedPhone;
            } else if (formattedPhone.length === 8) {
                formattedPhone = '22901' + formattedPhone;
            }

            const chatId = `${formattedPhone}@c.us`;
            console.log(`📤 Envoi vers: ${chatId}`);

            // Envoyer directement sans vérifier isRegisteredUser (évite les blocages)
            await client.sendMessage(chatId, message);
            console.log(`✅ Message envoyé à ${formattedPhone}`);
            resolve({ success: true, message: "WhatsApp message sent successfully." });

        } catch (error) {
            console.error("🚨 Error sending message:", error.message);
            resolve({ success: false, error: error.toString() });
        }
    });

    const timeout = new Promise((resolve) =>
        setTimeout(() => resolve({ success: false, error: "Timeout: le bot a mis trop de temps à répondre." }), SEND_TIMEOUT_MS)
    );

    try {
        const result = await Promise.race([sendWithTimeout, timeout]);
        const statusCode = result.success ? 200 : 500;
        res.status(statusCode).json(result);
    } catch (err) {
        res.status(500).json({ success: false, error: err.toString() });
    }
});

// Start the Express Server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`🚀 API Server running on port ${PORT}`);
    console.log(`➡️ Endpoint ready at: POST http://localhost:${PORT}/send`);
});
