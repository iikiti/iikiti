<script lang="ts">
	import { layoutDialogOpen, closeLayoutDialog } from './state';
	import { notifications } from '../../js/iikiti/notifications.js';
	import ModalDialog from '$components/ModalDialog.svelte';

	/**
	 * Layout dialog: lists the global shells (header, footer, sidebars, dialogs)
	 * grouped by role and lets an editor add, edit, enable and delete them.
	 *
	 * Uses the browser session (cookie) against /admin/layouts/shells, with a CSRF
	 * token from the list response on every write. Token-authenticated management
	 * is kept separately at /api/admin/shells for external tools.
	 */

	const SHELLS_URL = '/admin/layouts/shells';
	let csrfToken = $state('');

	type Shell = {
		id: number | null;
		name: string;
		role: string;
		priority: number;
		enabled: boolean;
		displayRules: { rule: string; config: Record<string, unknown> }[];
		blocks: unknown[];
	};

	const ROLE_OPTIONS = [
		{ value: 'header', label: 'Header' },
		{ value: 'footer', label: 'Footer' },
		{ value: 'aside', label: 'Sidebar' },
		{ value: 'dialog', label: 'Dialog' },
	];

	let shells = $state<Shell[]>([]);
	let loading = $state(false);
	let error = $state('');

	/** The shell currently being edited; null when only the list is shown. */
	let editing = $state<Shell | null>(null);
	let rulesText = $state('[]');

	const grouped = $derived(
		ROLE_OPTIONS.map((role) => ({
			...role,
			shells: shells.filter((s) => s.role === role.value).sort((a, b) => a.priority - b.priority),
		})),
	);

	function headers(): HeadersInit {
		return { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' };
	}

	async function load() {
		loading = true;
		error = '';
		try {
			const res = await fetch(SHELLS_URL, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (!res.ok) throw new Error(`Load failed (${res.status})`);
			const data = (await res.json()) as { csrfToken: string; shells: Shell[] };
			csrfToken = data.csrfToken;
			shells = data.shells;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Load failed';
		} finally {
			loading = false;
		}
	}

	function startNew(role: string) {
		editing = { id: null, name: '', role, priority: 0, enabled: true, displayRules: [], blocks: [] };
		rulesText = '[]';
	}

	function startEdit(shell: Shell) {
		editing = { ...shell };
		rulesText = JSON.stringify(shell.displayRules, null, 2);
	}

	async function save() {
		if (!editing) return;
		let rules: Shell['displayRules'];
		try {
			rules = JSON.parse(rulesText);
		} catch {
			error = 'Display rules must be valid JSON.';
			return;
		}
		const payload = { ...editing, displayRules: rules };
		const isNew = editing.id === null;
		const url = isNew ? SHELLS_URL : `${SHELLS_URL}/${editing.id}`;

		const res = await fetch(url, {
			method: isNew ? 'POST' : 'PUT',
			credentials: 'same-origin',
			headers: headers(),
			body: JSON.stringify(payload),
		});
		if (!res.ok) {
			const body = await res.json().catch(() => ({}));
			error = (body.error ?? `Save failed (${res.status})`) as string;
			return;
		}
		editing = null;
		error = '';
		await load();
	}

	async function remove(shell: Shell) {
		if (shell.id === null) return;
		const res = await fetch(`${SHELLS_URL}/${shell.id}`, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: headers(),
		});
		if (!res.ok) {
			notifications.notify({ message: 'Delete failed', type: 'error' });
			return;
		}
		await load();
	}

	$effect(() => {
		if ($layoutDialogOpen) void load();
	});
</script>

{#if $layoutDialogOpen}
	<ModalDialog title="Layout" onClose={closeLayoutDialog}>
		{#if error}<p class="iikiti-layout__error" role="alert">{error}</p>{/if}
		{#if loading}<p>Loading…</p>{/if}

		{#if editing}
			<form class="iikiti-layout__form" onsubmit={(e) => { e.preventDefault(); void save(); }}>
				<label>Name <input bind:value={editing.name} required /></label>
				<label>
					Role
					<select bind:value={editing.role}>
						{#each ROLE_OPTIONS as role (role.value)}
							<option value={role.value}>{role.label}</option>
						{/each}
					</select>
				</label>
				<label>Priority <input type="number" bind:value={editing.priority} /></label>
				<label><input type="checkbox" bind:checked={editing.enabled} /> Enabled</label>
				<label>
					Display rules (JSON)
					<textarea rows="6" bind:value={rulesText}></textarea>
				</label>
				<p class="iikiti-layout__hint">e.g. <code>[{`{"rule":"site","config":{"site_id":1}}`}]</code></p>
				<div class="iikiti-layout__actions">
					<button type="button" onclick={() => (editing = null)}>Cancel</button>
					<button type="submit">Save</button>
				</div>
			</form>
		{:else}
			{#each grouped as group (group.value)}
				<section class="iikiti-layout__group" data-shell-role={group.value}>
					<header>
						<h3>{group.label}</h3>
						<button type="button" onclick={() => startNew(group.value)}>+ Add</button>
					</header>
					{#if group.shells.length === 0}
						<p class="iikiti-layout__empty">No {group.label.toLowerCase()} shells.</p>
					{:else}
						<ul>
							{#each group.shells as shell (shell.id)}
								<li>
									<button type="button" onclick={() => startEdit(shell)}>
										{shell.name || '(unnamed)'} <small>priority {shell.priority}{shell.enabled ? '' : ' · disabled'}</small>
									</button>
									<button type="button" aria-label="Delete {shell.name}" onclick={() => remove(shell)}>Delete</button>
								</li>
							{/each}
						</ul>
					{/if}
				</section>
			{/each}
		{/if}
	</ModalDialog>
{/if}

<style>
	.iikiti-layout__group { margin-bottom: 1rem; }
	.iikiti-layout__group header { display: flex; justify-content: space-between; align-items: center; }
	.iikiti-layout__group ul { list-style: none; padding: 0; margin: 0.25rem 0 0; }
	.iikiti-layout__group li { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.25rem; }
	.iikiti-layout__empty { opacity: 0.7; font-size: 0.85rem; }
	.iikiti-layout__form { display: grid; gap: 0.6rem; }
	.iikiti-layout__form label { display: grid; gap: 0.25rem; font-size: 0.9rem; }
	.iikiti-layout__actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
	.iikiti-layout__error { color: #b42318; font-size: 0.9rem; }
	.iikiti-layout__hint { font-size: 0.8rem; opacity: 0.75; }
</style>
