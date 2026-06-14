<style>
    :root {
        --g-blue: #4285f4;
        --g-red: #ea4335;
        --g-yellow: #fbbc05;
        --g-green: #34a853;
        --glass: rgba(255, 255, 255, 0.03);
    }

    /* OVERLAY corrigé : pointer-events empêche le blocage du clic */
    #connectivity-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(40px) saturate(150%);
        -webkit-backdrop-filter: blur(40px) saturate(150%);
        z-index: 1000000;
        display: none;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1);
        pointer-events: none; /* Crucial : laisse passer les clics quand invisible */
    }

    #connectivity-overlay.active { 
        opacity: 1; 
        display: flex; 
        pointer-events: all; /* Active les clics uniquement si affiché */
    }

    /* CARTE FLUENT DESIGN */
    .guardian-card {
        background: linear-gradient(165deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.02) 100%);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 32px;
        padding: 48px 32px;
        width: 90%;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 40px 100px rgba(0,0,0,0.5);
        transform: scale(0.9);
        transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    #connectivity-overlay.active .guardian-card { transform: scale(1); }

    /* ANIMATION WIFI SVG */
    .wifi-icon { width: 80px; height: 80px; margin-bottom: 24px; margin-left: auto; margin-right: auto;}
    .wifi-wave { fill: none; stroke: var(--status-color, var(--g-red)); stroke-width: 2; stroke-linecap: round; opacity: 0.3; }
    .wifi-wave.active { opacity: 1; animation: wifi-pulse 1.5s infinite; }
    .wifi-wave:nth-child(2) { animation-delay: 0.2s; }
    .wifi-wave:nth-child(3) { animation-delay: 0.4s; }

    @keyframes wifi-pulse {
        0% { opacity: 0.3; }
        50% { opacity: 1; filter: drop-shadow(0 0 5px var(--status-color)); }
        100% { opacity: 0.3; }
    }

    .guardian-title {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.5rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 12px;
        letter-spacing: -0.5px;
    }

    .guardian-desc {
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-size: 0.95rem;
        color: rgba(255,255,255,0.6);
        line-height: 1.6;
        margin-bottom: 32px;
    }

    .retry-btn {
        background: #fff;
        color: #000;
        border: none;
        padding: 14px 32px;
        border-radius: 100px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.3s ease;
    }

    .retry-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(255,255,255,0.2); }
    .retry-btn.loading { opacity: 0.7; pointer-events: none; }

    #connectivity-toast {
        position: fixed;
        top: 30px;
        left: 50%;
        transform: translateX(-50%) translateY(-100px);
        background: var(--g-green);
        color: #fff;
        padding: 12px 24px;
        border-radius: 100px;
        font-weight: 600;
        font-size: 0.9rem;
        z-index: 1000001;
        box-shadow: 0 20px 40px rgba(52, 168, 83, 0.3);
        transition: transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    #connectivity-toast.show { transform: translateX(-50%) translateY(0); }
</style>

<div id="connectivity-overlay">
    <div class="guardian-card">
        <div class="wifi-icon">
            <svg viewBox="0 0 24 24" id="svg-wifi">
                <path class="wifi-wave" d="M5 12.55a11 11 0 0 1 14.08 0" />
                <path class="wifi-wave" d="M1.42 9a16 16 0 0 1 21.16 0" />
                <path class="wifi-wave active" d="M8.53 16.11a6 6 0 0 1 6.95 0" />
                <circle cx="12" cy="20" r="1" fill="currentColor" />
            </svg>
        </div>
        <div class="guardian-title" id="g-title">L'application est hors-ligne</div>
        <div class="guardian-desc" id="g-desc">Nous mettons vos données en sécurité dans votre navigateur jusqu'au retour de la connexion.</div>
        <button class="retry-btn" onclick="checkRealConnectivity(this)">
            <span>Vérifier la connexion</span>
        </button>
    </div>
</div>

<div id="connectivity-toast">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
    Connexion rétablie
</div>

<script>
    const overlay = document.getElementById('connectivity-overlay');
    const toast = document.getElementById('connectivity-toast');
    const gTitle = document.getElementById('g-title');
    const gDesc = document.getElementById('g-desc');
    let wasOffline = false;
    let isChecking = false;

    // Test de ping amélioré vers une ressource externe fiable (Google Favicon)
    async function isConnectedToServer() {
        if (!navigator.onLine) return false;
        try {
            // Utilisation d'un timeout court pour ne pas bloquer l'UI
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 3500);
            
            await fetch('https://www.google.com/favicon.ico', { 
                mode: 'no-cors', 
                cache: 'no-store',
                signal: controller.signal 
            });
            clearTimeout(timeoutId);
            return true;
        } catch (e) {
            return false;
        }
    }

    async function checkState() {
        if (isChecking) return;
        isChecking = true;

        const online = navigator.onLine;
        
        if (!online) {
            setUIOffline();
        } else {
            const realWeb = await isConnectedToServer();
            if (realWeb) {
                setUIOnline();
            } else {
                setUISlow();
            }
        }
        isChecking = false;
    }

    function setUIOffline() {
        wasOffline = true;
        document.documentElement.style.setProperty('--status-color', 'var(--g-red)');
        gTitle.innerText = "Connexion perdue";
        gDesc.innerText = "Vos modifications sont sauvegardées localement. L'encodage reprendra dès le retour du signal.";
        overlay.style.display = 'flex';
        // Petit délai pour l'animation d'opacité
        setTimeout(() => overlay.classList.add('active'), 10);
    }

    function setUISlow() {
        wasOffline = true;
        document.documentElement.style.setProperty('--status-color', 'var(--g-yellow)');
        gTitle.innerText = "Serveur injoignable";
        gDesc.innerText = "Le serveur ITMCD ne répond pas. Vérifiez votre accès internet ou contactez l'administrateur.";
        overlay.style.display = 'flex';
        setTimeout(() => overlay.classList.add('active'), 10);
    }

    function setUIOnline() {
        if (wasOffline) {
            overlay.classList.remove('active');
            setTimeout(() => {
                overlay.style.display = 'none';
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 4000);
            }, 600);
            wasOffline = false;
        }
    }

    async function checkRealConnectivity(btn) {
        if (btn.classList.contains('loading')) return;
        
        btn.classList.add('loading');
        const span = btn.querySelector('span');
        const oldText = span.innerText;
        span.innerText = "Vérification...";
        
        const ok = await isConnectedToServer();
        if (ok) {
            setUIOnline();
        } else {
            btn.parentElement.style.animation = "shake 0.4s ease";
            setTimeout(() => btn.parentElement.style.animation = "", 400);
        }
        
        btn.classList.remove('loading');
        span.innerText = oldText;
    }

    // Animation de secousse
    const styleShake = document.createElement('style');
    styleShake.innerHTML = "@keyframes shake { 0%, 100% {transform:translateX(0);} 25% {transform:translateX(-8px);} 75% {transform:translateX(8px);} }";
    document.head.appendChild(styleShake);

    window.addEventListener('online', checkState);
    window.addEventListener('offline', checkState);
    
    // Vérification automatique toutes les 15 secondes uniquement si on est déjà hors ligne
    setInterval(() => { 
        if (wasOffline && !isChecking) checkState(); 
    }, 15000);
    
    // Initialisation au chargement
    checkState();
</script>