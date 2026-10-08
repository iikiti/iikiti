import { mount } from 'svelte';
import App from './svelte/admin/App.svelte';
import { installTour } from './js/iikiti/tour.js';
import { setSavedTimeZone } from './js/iikiti/time/temporal.js';
import './styles/admin.css';

installTour();

const root = document.querySelector('#admin-app');

if (root) {
    // The saved zone must be set before any component formats a date.
    setSavedTimeZone(root.dataset.timeZone ?? '');

    // Svelte 5's mount() appends to the target rather than replacing its
    // content — clear the server-rendered loading spinner first.
    root.innerHTML = '';

    mount(App, {
        target: root,
        props: {
            apiToken: root.dataset.apiToken ?? '',
            apiBase: root.dataset.apiBase ?? '/api',
            currentUser: root.dataset.currentUser ?? '',
            debug: root.dataset.debug === 'true',
        },
    });
}

export default App;
