import { mount } from 'svelte';
import App from './svelte/admin/App.svelte';
import './styles/admin.css';

const root = document.querySelector('#admin-app');

if (root) {
    // Svelte 5's mount() appends to the target rather than replacing its
    // content — clear the server-rendered loading spinner first.
    root.innerHTML = '';

    mount(App, {
        target: root,
        props: {
            apiToken: root.dataset.apiToken ?? '',
            apiBase: root.dataset.apiBase ?? '/api',
            debug: root.dataset.debug === 'true',
        },
    });
}

export default App;
