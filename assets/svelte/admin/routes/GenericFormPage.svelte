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

	let formData: Record<string, any> = $state({});
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

	async function handleSubmit() {
		if (!screen?.apiPath) return;

		loading = true;
		error = null;
		try {
			if (isCreate) {
				await api.request(screen.apiPath, {
					method: 'POST',
					body: JSON.stringify(formData),
				});
			} else {
				await api.request(`${screen.apiPath}/${id}`, {
					method: 'PUT',
					body: JSON.stringify(formData),
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
		<ErrorBoundary message={error} onRetry={handleSubmit} />
	{/if}

	<Form fields={formFields} values={formData} onsubmit={handleSubmit} />

	<div class="mt-6 flex justify-end gap-2">
		<Button variant="secondary" onclick={() => history.back()}>Cancel</Button>
		<Button variant="primary" type="submit" disabled={loading} onclick={handleSubmit}>
			{loading ? 'Saving…' : (isCreate ? 'Create' : 'Save')}
		</Button>
	</div>
{/if}
