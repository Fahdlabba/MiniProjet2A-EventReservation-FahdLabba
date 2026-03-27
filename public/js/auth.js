/**
 * Convertit un ArrayBuffer en base64url (WebAuthn requirement)
 */
function bufferToBase64Url(buffer) {
    const bytes = Array.from(new Uint8Array(buffer));
    const binary = bytes.map(b => String.fromCharCode(b)).join('');
    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

/**
 * Convertit base64url en ArrayBuffer
 */
function base64UrlToBuffer(base64url) {
    let base64 = base64url
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const padding = '='.repeat((4 - base64.length % 4) % 4);
    base64 += padding;

    const binary = atob(base64);
    const bytes = Uint8Array.from(binary, c => c.charCodeAt(0));
    return bytes.buffer;
}

/**
 * Inscription avec passkey
 */
async function registerPasskey(email, displayName) {
    // 1. Obtenir les options du serveur
    const optionsRes = await fetch('/api/auth/register/options', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, displayName })
    });

    if (!optionsRes.ok) {
        const err = await optionsRes.json();
        throw new Error(err.error || 'Échec options');
    }

    const options = await optionsRes.json();

    // 2. Créer la credential via l'API navigateur
    const credential = await navigator.credentials.create({
        publicKey: {
            ...options,
            challenge: base64UrlToBuffer(options.challenge),
            user: {
                ...options.user,
                id: base64UrlToBuffer(options.user.id)
            },
            excludeCredentials: options.excludeCredentials?.map(c => ({
                ...c,
                id: base64UrlToBuffer(c.id)
            }))
        }
    });

    // 3. Envoyer la réponse au serveur
    const verifyRes = await fetch('/api/auth/register/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email,
            credential: {
                id: credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                response: {
                    clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                    attestationObject: bufferToBase64Url(credential.response.attestationObject)
                },
                type: credential.type,
                clientExtensionResults: credential.getClientExtensionResults()
            }
        })
    });

    const result = await verifyRes.json();
    if (!verifyRes.ok) throw new Error(result.error || 'Échec vérification');

    // 4. Stocker les tokens pour les requêtes futures
    if (result.token) {
        localStorage.setItem('jwt_token', result.token);
        localStorage.setItem('refresh_token', result.refresh_token);
    }

    return result;
}

/**
 * Connexion avec passkey existante
 */
async function loginWithPasskey() {
    // 1. Obtenir les options de connexion
    const optionsRes = await fetch('/api/auth/login/options', {
        method: 'POST'
    });

    if (!optionsRes.ok) {
        const err = await optionsRes.json();
        throw new Error(err.error || 'Échec options login');
    }

    const options = await optionsRes.json();

    // 2. Demander l'authentification à l'utilisateur
    const assertion = await navigator.credentials.get({
        publicKey: {
            ...options,
            challenge: base64UrlToBuffer(options.challenge),
            allowCredentials: options.allowCredentials?.map(c => ({
                ...c,
                id: base64UrlToBuffer(c.id)
            }))
        }
    });

    // 3. Vérifier avec le serveur
    const verifyRes = await fetch('/api/auth/login/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            credential: {
                id: assertion.id,
                rawId: bufferToBase64Url(assertion.rawId),
                response: {
                    clientDataJSON: bufferToBase64Url(assertion.response.clientDataJSON),
                    authenticatorData: bufferToBase64Url(assertion.response.authenticatorData),
                    signature: bufferToBase64Url(assertion.response.signature),
                    userHandle: assertion.response.userHandle ? bufferToBase64Url(assertion.response.userHandle) : null
                },
                type: assertion.type,
                clientExtensionResults: assertion.getClientExtensionResults()
            }
        })
    });

    const result = await verifyRes.json();
    if (!verifyRes.ok) throw new Error(result.error || 'Échec authentification');

    if (result.token) {
        localStorage.setItem('jwt_token', result.token);
        localStorage.setItem('refresh_token', result.refresh_token);
    }

    return result;
}

/**
 * Ajoute le token JWT aux en-têtes des requêtes fetch
 */
function authFetch(url, options = {}) {
    const token = localStorage.getItem('jwt_token');
    const headers = {
        ...(options.headers || {}),
        'Authorization': token ? `Bearer ${token}` : ''
    };

    return fetch(url, { ...options, headers });
}

/**
 * Rafraîchit le token JWT expiré
 */
async function refreshToken() {
    const refresh = localStorage.getItem('refresh_token');
    if (!refresh) return false;

    const res = await fetch('/api/token/refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refresh })
    });

    if (!res.ok) {
        localStorage.removeItem('jwt_token');
        localStorage.removeItem('refresh_token');
        return false;
    }

    const data = await res.json();
    localStorage.setItem('jwt_token', data.token);
    if (data.refresh_token) {
        localStorage.setItem('refresh_token', data.refresh_token);
    }
    return true;
}
