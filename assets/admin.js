import { mount } from 'svelte';
import App from './svelte/admin/App.svelte';
import { installTour } from './js/iikiti/tour.js';
import { setSavedTimeZone } from './js/iikiti/time/temporal.js';
import './styles/admin.css';

installTour();

const root = document.querySelector('#admin-app');

if (root) {
    const apiBase = root.dataset.apiBase ?? '/api';

    void (async () => {
        try {
            let session = {};
            if (!root.dataset.apiToken) {
                const response = await fetch(`${apiBase}/auth/session-token`, {
                    method: 'POST',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'X-SITE-HOST': root.dataset.siteHost ?? '',
                    'X-SITE-SCHEME': root.dataset.siteScheme ?? 'https',
                },
                });
                if (!response.ok) {
                    throw new Error('Authentication required');
                }
                session = await response.json();
            }

            const apiToken = root.dataset.apiToken || session.token;
            if (typeof apiToken !== 'string' || apiToken.length === 0) {
                throw new Error('Authentication required');
            }

            // The saved zone must be set before any component formats a date.
            setSavedTimeZone(root.dataset.timeZone || session.timeZone || '');

            // Svelte 5's mount() appends to the target rather than replacing its
            // content — clear the server-rendered loading spinner first.
            root.innerHTML = '';

            mount(App, {
                target: root,
                props: {
                    apiToken,
                    apiBase,
                    currentUser: root.dataset.currentUser || session.username || '',
                    debug: root.dataset.debug === 'true',
                    siteHost: root.dataset.siteHost ?? '',
                    siteScheme: root.dataset.siteScheme ?? 'https',
                },
            });
        } catch {
            const apiLogin = new URL(apiBase, window.location.href);
            apiLogin.pathname = apiLogin.pathname.replace(/\/api\/?$/, '/login');
            apiLogin.searchParams.set('siteHost', root.dataset.siteHost ?? '');

            const message = document.createElement('p');
            message.textContent = 'Sign in to open administration.';
            const link = document.createElement('a');
            link.href = apiLogin.href;
            link.textContent = 'Continue to sign in';
            root.replaceChildren(message, link);
        }
    })();
}

export default App;
