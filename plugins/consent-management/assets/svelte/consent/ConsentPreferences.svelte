<script lang="ts">
	interface Props {
		open: boolean;
		onClose: () => void;
		onSave: (categories: { analytics: boolean; marketing: boolean }) => void;
		categories: { necessary: boolean; analytics: boolean; marketing: boolean };
	}

	let { open, onClose, onSave, categories }: Props = $props();

	let analytics = $derived(categories.analytics);
	let marketing = $derived(categories.marketing);

	function save() {
		onSave({ analytics, marketing });
		onClose();
	}
</script>

<div class="fixed inset-0 z-[60] flex items-center justify-center p-4" aria-hidden={!open}>
	<button
		type="button"
		aria-label="Close privacy preferences"
		tabindex="-1"
		disabled={!open}
		class="fixed inset-0 border-0 p-0 bg-black/40 backdrop-blur-sm transition-opacity"
		class:opacity-100={open}
		class:opacity-0={!open}
		onclick={onClose}
	></button>
	<dialog
		class="w-full max-w-md bg-surface rounded-lg border border-border p-6 shadow-xl"
		open={open}
		class:pointer-events-none={!open}
	>
		<h2 class="text-lg font-semibold text-text mb-4">Privacy preferences</h2>
		<p class="text-sm text-text-muted mb-6">
			Choose which optional categories you allow. Necessary cookies are always used.
		</p>
		<form class="space-y-4">
			<ConsentToggle
				category="analytics"
				label="Analytics"
				description="Helps us understand how visitors use the site."
				checked={analytics}
				onchange={(v) => (analytics = v)}
			/>
			<ConsentToggle
				category="marketing"
				label="Marketing"
				description="Used to show you relevant content and ads."
				checked={marketing}
				onchange={(v) => (marketing = v)}
			/>
		</form>
		<div class="flex justify-end gap-3 mt-6">
			<button type="button" class="px-4 py-2 rounded text-text hover:bg-surface-hover" onclick={onClose}>
				Cancel
			</button>
			<button type="button" class="px-4 py-2 rounded bg-accent text-white hover:bg-accent-hover" onclick={save}>
				Save preferences
			</button>
		</div>
	</dialog>
	</div>
