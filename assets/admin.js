import { mount } from 'svelte';
import App from './svelte/admin/App.svelte';
import './styles/admin.css';

const root = document.querySelector('#admin-app');

if (root) {
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
