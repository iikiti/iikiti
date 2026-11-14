<script lang="ts">
	import ConsentPreferences from './ConsentPreferences.svelte';
	import ConsentToggle from './ConsentToggle.svelte';

	interface Props {
		onSave: (categories: { analytics: boolean; marketing: boolean }) => void;
		categories: { necessary: boolean; analytics: boolean; marketing: boolean };
	}

	let { onSave, categories }: Props = $props();

	let analytics = $derived(categories.analytics);
	let marketing = $derived(categories.marketing);
	let preferencesOpen = $state(false);

	function acceptAll() {
		onSave({ analytics: true, marketing: true });
	}
	function rejectNonNecessary() {
		onSave({ analytics: false, marketing: false });
	}
	function savePreferences() {
		onSave({ analytics, marketing });
		preferencesOpen = false;
	}
</script>

<div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 shadow-lg z-[55]">
	<div class="max-w-xl mx-auto">
		<div class="flex flex-col md:flex-row md:items-center gap-4">
			<div class="text-sm text-text">
				<p class="font-medium">We respect your privacy</p>
				<p class="text-xs text-text-muted">
					We use cookies to keep the site working and, with your consent, for analytics
					and marketing. You can change your choices at any time.
				</p>
			</div>
			<div class="flex flex-wrap justify-end gap-2">
				<button
					type="button"
					class="px-4 py-2 rounded text-xs font-medium bg-accent text-white hover:bg-accent-hover"
					onclick={acceptAll}
				>
					Accept all
				</button>
				<button
					type="button"
					class="px-4 py-2 rounded text-xs font-medium text-accent hover:bg-surface-hover"
					onclick={rejectNonNecessary}
				>
					Reject non-necessary
				</button>
				<button
					type="button"
					class="px-4 py-2 rounded text-xs font-medium text-text hover:bg-surface-hover"
					onclick={() => (preferencesOpen = true)}
				>
					Preferences
				</button>
			</div>
		</div>
	</div>
</div>

	<ConsentPreferences
		{preferencesOpen}
		onClose={() => (preferencesOpen = false)}
		onSave={savePreferences}
		categories={categories}
	/>