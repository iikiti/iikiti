<script lang="ts">
	import { onMount } from 'svelte';
	import Form from '../components/Form.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import Breadcrumb from '../components/Breadcrumb.svelte';
	import Button from '../components/Button.svelte';
	import type { AdminScreen } from '$types';

	interface Props {
		api: any;
		debug?: boolean;
		screen: AdminScreen | null;
		id?: string | number;
	}

	let { api, debug = false, screen, id }: Props = $props();

	let values: Record<string, any> = $state({});
	let loading = $state(false);
	let error: string | null = $state(null);

	const fields = $derived(screen?.config?.fields ?? []);
	const isCreate = $derived(!id);

	const formFields = $derived(
		(fields ?? []).map((f: any) => ({
			key: f.key,
			label: f.label,
			type: f.type ?? 'text',
			required: f.required ?? false,
			placeholder: f.placeholder,
			options: f.options,
		})),
	);

	async function load() {
		if (!screen?.apiPath || !id) {
			return;
		}

		loading = true;
		error = null;
		try {
			const record: any = await api.request(`${screen.apiPath}/${id}`);
			const patch: Record<string, any> = {};
			for (const f of formFields) {
				const v = record[f.key];
				if (f.type === 'json' && (Array.isArray(v) || (v && typeof v === 'object'))) {
					patch[f.key] = JSON.stringify(v, null, 2);
				} else if (v !== undefined && v !== null) {
					patch[f.key] = v;
				}
			}
			values = patch;
		} catch (e: any) {
			error = e.message ?? 'Failed to load record';
		} finally {
			loading = false;
		}
	}

	onMount(() => {
		if (!isCreate) {
			load();
		}
	});

	$effect(() => {
		if (id && !isCreate) {
			load();
		}
	});

	function preparePayload(data: Record<string, any>): Record<string, any> | null {
		const payload: Record<string, any> = { ...data };
		for (const f of formFields) {
			if (f.type === 'json' && typeof payload[f.key] === 'string' && payload[f.key] !== '') {
				try {
					payload[f.key] = JSON.parse(payload[f.key]);
				} catch {
					error = `Invalid JSON in ${f.label}`;

					return null;
				}
			}
		}

		return payload;
	}

	async function handleSubmit(data: Record<string, any>) {
		const payload = preparePayload(data);
		if (!payload || !screen?.apiPath) {
			return;
		}

		loading = true;
		error = null;
		try {
			if (isCreate) {
				await api.request(screen.apiPath, {
					method: 'POST',
					body: JSON.stringify(payload),
				});
			} else {
				await api.request(`${screen.apiPath}/${id}`, {
					method: 'PUT',
					body: JSON.stringify(payload),
				});
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to save';
		} finally {
			loading = false;
		}
	}
</script>

{#if !screen}
	<ErrorBoundary message="No screen configuration found." />
{:else if loading}
	<PageHeader title={isCreate ? `New ${screen.title}` : `Edit ${screen.title}`} description={screen.description} />
	<LoadingState label="Loading…" />
{:else}
	<div class="mb-4">
		<Breadcrumb
			items={[
				{ label: screen.title, path: screen.path },
				{ label: isCreate ? 'New' : `#${id}` },
			]}
		/>
	</div>

	<PageHeader title={isCreate ? `New ${screen.title}` : `Edit ${screen.title}`} description={screen.description} />

	{#if error}
		<ErrorBoundary message={error} onRetry={isCreate ? undefined : load} />
	{/if}

	<Form fields={formFields} values={values} onsubmit={handleSubmit}>
		<Button variant="secondary" onclick={() => history.back()}>Cancel</Button>
		<Button variant="primary" type="submit" disabled={loading}>
			{loading ? 'Saving…' : (isCreate ? 'Create' : 'Save')}
		</Button>
	</Form>
{/if}
