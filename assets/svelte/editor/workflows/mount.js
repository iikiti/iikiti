import { mount } from 'svelte';
import Workflow from './Workflow.svelte';

/**
 * Mounts the front-end workflow engine onto a [data-flow] element. The element
 * must carry `data-flow` (flow name), `data-flow-action` (form action) and
 * `data-flow-csrf` (CSRF token).
 */
export function launchWorkflow(host) {
	const flow = host.getAttribute('data-flow') || '';
	const action = host.getAttribute('data-flow-action') || window.location.pathname;
	const csrf = host.getAttribute('data-flow-csrf') || '';
	if (!flow) return;

	mount(Workflow, {
		target: host,
		props: { flow, action, csrf },
	});
}
